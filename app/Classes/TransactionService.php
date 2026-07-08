<?php

namespace App\Classes;

use App\Jobs\SendTransactionCallback;
use App\Models\CashbackRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TransactionService
{
    public static function process(array $apiData, ?User $user = null): array
    {
        return DB::transaction(function () use ($apiData, $user) {
            $transactionType = $apiData['transaction_type'];

            // Extract discount and pricing information
            $discountAmount = floatval($apiData['discount_amount'] ?? 0);
            $promotionId = $apiData['promotion_id'] ?? null;

            // Get the final amount to charge user (after discount) from apiData
            // Priority: final_amount > (amount - discount_amount) > amount
            $finalAmount = floatval(
                $apiData['final_amount'] ?? 
                (($apiData['amount'] ?? 0) - $discountAmount) ??
                ($apiData['amount'] ?? 0)
            );

            // Get the original/API amount (before discount)
            $originalAmount = floatval($apiData['amount'] ?? 0);

            // Calculate balance changes based on final amount (what user actually pays)
            $balanceBefore = floatval($user->wallet_balance);
            $balanceAfter = $apiData["status"] === "success" ? $balanceBefore - $finalAmount : $user->wallet_balance;

            // Deduct wallet only if transaction is successful
            if ($apiData["status"] === "success") {
                $user->wallet_balance = $balanceAfter;
                $user->save();
            }

            // Log the transaction details for debugging
            Log::info("Transaction Processing", [
                'user_id' => $user->id,
                'original_amount' => $originalAmount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'promotion_id' => $promotionId,
                'status' => $apiData["status"]
            ]);

            // Create the transaction with proper discount and promotion tracking
            $tx_data = array_merge($apiData, [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'user_id' => $user->id,
                'amount' => $finalAmount,           // Final amount user paid (after discount)
                'discount_amount' => $discountAmount,  // Track discount separately
                'promotion_id' => $promotionId,     // Link to promotion used
            ]);

            // Remove final_amount from tx_data to avoid storage conflicts
            unset($tx_data['final_amount']);

            $transaction = Transaction::create($tx_data);

            // Cashback: a flat, per-service-type wallet credit — replaces
            // the old per-role Discount pricing. Only on success, and keyed
            // off the same transaction_type stored on the row above (e.g.
            // 'airtime_recharge'), not the Discount model's own "type".
            if ($apiData['status'] === 'success') {
                // Referral commission — only on a transaction that actually
                // went through (previously fired unconditionally, so a
                // failed purchase still paid the referrer).
                self::distributeCommission($user, $finalAmount, $transactionType);

                self::creditCashback($user, $finalAmount, $transactionType);

                // Event rewards keyed off purchase/funding volume — computed
                // fresh from Transaction data each time, so this is safe to
                // call on every successful transaction (see EventService).
                EventService::checkAndAward($user);
            }

            AdminNotifier::notifyTransaction($transaction);

            $label = ucwords(str_replace('_', ' ', $transactionType));
            $user->notify(new AppNotification(
                $apiData['status'] === 'success' ? 'transaction_success' : 'transaction_failed',
                $apiData['status'] === 'success' ? "{$label} successful" : "{$label} failed",
                $apiData['status'] === 'success'
                    ? "Your {$label} purchase of ₦{$finalAmount} was successful. Ref: {$transaction->transaction_reference}"
                    : "Your {$label} purchase of ₦{$finalAmount} could not be completed.",
            ));

            SendTransactionCallback::dispatch($user, $transaction);

            // Enrich transaction response with discount info
            $transactionArray = $transaction->toArray();
            $transactionArray['discount_applied'] = [
                'discount_amount' => $discountAmount,
                'original_amount' => $originalAmount,
                'final_amount' => $finalAmount,
                'promotion_id' => $promotionId,
            ];

            return $transactionArray;
        });
    }

    public static function generateTransactionReference(): string
    {
        $settings = Setting::first();
        $prefix = $settings?->invoice_prefix ?: 'TXN-';
        $suffix = $settings?->invoice_suffix ?: '';

        return $prefix . now()->format('YmdHis') . '-' . strtoupper(Str::random(6)) . $suffix;
    }

    protected static function calculateServiceFee(float $amount): float
    {
        return round($amount * 0.01, 2); // 1% service fee
    }

    protected static function distributeCommission(User $user, float $amount, string $transactionType): void
    {
        if ($user->referred_by) {
            $referrer = User::find($user->referred_by);
            if (!$referrer) {
                return;
            }

            $rate = floatval(Setting::first()?->referral_commission_rate ?? 2.00);
            $commission = round($amount * ($rate / 100), 2);
            if ($commission <= 0) {
                return;
            }

            // Credited to referral_balance (a separate, spendable-on-demand
            // pot the user converts to wallet_balance themselves — see
            // CustomerController::convertReferralToWallet), not
            // wallet_balance directly. total_referral_earnings is a
            // lifetime counter that never drains, unlike referral_balance.
            $referrer->increment('referral_balance', $commission);
            $referrer->increment('total_referral_earnings', $commission);

            $referrer->notify(new AppNotification(
                'referral_commission',
                'Referral commission earned',
                "You earned ₦{$commission} from @{$user->username}'s purchase — added to your referral balance.",
            ));
        }
    }

    protected static function creditCashback(User $user, float $amount, string $transactionType): void
    {
        $rate = CashbackRate::where('service_type', $transactionType)
            ->where('active', true)
            ->first();

        if (!$rate || $rate->percentage <= 0) {
            return;
        }

        $cashback = round($amount * ($rate->percentage / 100), 2);
        if ($cashback > 0) {
            $user->increment('wallet_balance', $cashback);
        }
    }

    public static function fundUser(
        User $user,
        float $amount,
        string $type = 'credit',
        ?string $note = null,
        string $transactionType = 'manual_funding',
        string $provider = 'admin',
        ?string $receiver = null,
        ?string $relatedReference = null,
    ): array
{
    return DB::transaction(function () use ($user, $amount, $type, $note, $transactionType, $provider, $receiver, $relatedReference) {
        $balanceBefore = $user->wallet_balance;

        if ($type === 'credit') {
            $user->increment('wallet_balance', $amount);
        } elseif ($type === 'debit') {
            if ($user->wallet_balance < $amount) {
                throw new \Exception('Insufficient balance for debit.');
            }
            $user->decrement('wallet_balance', $amount);
        } else {
            throw new \Exception('Invalid transaction type.');
        }

        $balanceAfter = $user->fresh()->wallet_balance;

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'transaction_type' => $transactionType,
            'provider' => $provider,
            'account_or_phone' => $user->phone,
            'amount' => $amount,
            "plan_type" => $type,
            'status' => 'success',
            'transaction_reference' => self::generateTransactionReference(),
            'related_reference' => $relatedReference,
            'funding_method' => "manual",
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'response_message' => $note ?? (ucfirst($type) . ' by admin'),
            'platform' => 'web',
            "receiver" => $receiver ?? $user->username,
        ]);

        return $transaction->toArray();
    });
}

}
