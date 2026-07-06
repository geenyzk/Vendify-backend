<?php

namespace App\Class;

use App\Jobs\SendTransactionCallback;
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

            $amount = floatval($apiData['discount_amount'] ?? $apiData['amount']);
            $balanceBefore = floatval($user->wallet_balance);
            $balanceAfter = $apiData["status"] === "success"? $balanceBefore - floatval($apiData['amount']): $user->wallet_balance;

            // Optional: Deduct wallet (if not done via API balance already)
            $user->wallet_balance = $balanceAfter;
            $user->save();

            // Create the transaction
            $tx_data = array_merge($apiData, [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'user_id' => $user->id,
                "amount" => $amount,
            ]);
            $transaction = Transaction::create($tx_data);

<<<<<<< HEAD
            // Optional: Commission distribution
            self::distributeCommission($user, $amount, $transactionType);
=======
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
>>>>>>> edbac78 (feat: Add in-app notifications for wallet transactions and airtime-to-cash requests, including admin alerts and user notifications)

            $label = ucwords(str_replace('_', ' ', $transactionType));
            $user->notify(new AppNotification(
                $apiData['status'] === 'success' ? 'transaction_success' : 'transaction_failed',
                $apiData['status'] === 'success' ? "{$label} successful" : "{$label} failed",
                $apiData['status'] === 'success'
                    ? "Your {$label} purchase of ₦{$finalAmount} was successful. Ref: {$transaction->transaction_reference}"
                    : "Your {$label} purchase of ₦{$finalAmount} could not be completed.",
            ));

            SendTransactionCallback::dispatch($user, $transaction);

            return $transaction->toArray();
        });
    }

    public static function generateTransactionReference(): string
    {
        return 'TXN-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
    }

    protected static function calculateServiceFee(float $amount): float
    {
        return round($amount * 0.01, 2); // 1% service fee
    }

    protected static function distributeCommission(User $user, float $amount, string $transactionType): void
    {
        // Implement your logic here. Example:
        if ($user->referrer_id) {
            $referrer = User::find($user->referrer_id);
            $commission = round($amount * 0.02, 2); // 2% commission
            $referrer->wallet_balance += $commission;
            $referrer->save();

<<<<<<< HEAD
            // Optionally log it or create a commission record
=======
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
>>>>>>> edbac78 (feat: Add in-app notifications for wallet transactions and airtime-to-cash requests, including admin alerts and user notifications)
        }
    }

    public static function fundUser(User $user, float $amount, string $type = 'credit'): array
{
    return DB::transaction(function () use ($user, $amount, $type) {
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
            'transaction_type' => 'manual_funding',
            'provider' => 'admin',
            'account_or_phone' => $user->phone,
            'amount' => $amount,
            "plan_type" => $type,
            'status' => 'success',
            'transaction_reference' => self::generateTransactionReference(),
            'funding_method' => "manual",
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'response_message' => ucfirst($type) . ' by admin',
            'platform' => 'web',
        ]);

        return $transaction->toArray();
    });
}

}
