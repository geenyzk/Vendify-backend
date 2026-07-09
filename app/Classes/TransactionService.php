<?php

namespace App\Classes;

use App\Exceptions\InsufficientBalanceException;
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
    /**
     * Hold the purchase amount BEFORE the vendor is called, so value is
     * never delivered on credit. Locks the wallet row, refuses (throws) if
     * it can't cover the amount, then debits — all atomic. The caller
     * (VendorBase::process) refunds via refund() if the vendor then fails.
     *
     * @return array{balance_before: float, balance_after: float}
     */
    public static function reserve(User $user, float $amount): array
    {
        return DB::transaction(function () use ($user, $amount) {
            // Lock so two concurrent purchases can't both pass the check
            // against the same balance and each debit — the lock makes them
            // serialize, and the second sees the first's debit.
            $locked = User::whereKey($user->id)->lockForUpdate()->first() ?? $user;
            $before = (float) $locked->wallet_balance;

            if ($amount > 0 && $before < $amount) {
                throw new InsufficientBalanceException('Insufficient balance to complete this transaction.');
            }

            if ($amount > 0) {
                $locked->decrement('wallet_balance', $amount);
            }

            return [
                'balance_before' => $before,
                'balance_after' => $before - $amount,
            ];
        });
    }

    /**
     * Return a previously-reserved amount to the wallet — the fail path
     * (vendor rejected, threw, or a pending purchase that ultimately failed).
     */
    public static function refund(User $user, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $amount) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if ($locked) {
                $locked->increment('wallet_balance', $amount);
            }
        });
    }

    /**
     * Record the transaction row for a purchase whose funds are ALREADY
     * reserved (see reserve()). Does not move the wallet again — it only
     * writes the row, fires notifications/callbacks, and (on an immediate
     * success) pays out rewards. `$reservation` carries the balance figures
     * captured at reserve time so the row reflects the real debit.
     *
     * @param array{balance_before: float, balance_after: float} $reservation
     */
    public static function record(array $apiData, User $user, array $reservation): array
    {
        return DB::transaction(function () use ($apiData, $user, $reservation) {
            $transactionType = $apiData['transaction_type'];
            $discountAmount = floatval($apiData['discount_amount'] ?? 0);
            $promotionId = $apiData['promotion_id'] ?? null;

            // Final amount user pays (after discount). Priority:
            // final_amount > (amount - discount) > amount.
            $finalAmount = floatval(
                $apiData['final_amount']
                ?? (($apiData['amount'] ?? 0) - $discountAmount)
                ?? ($apiData['amount'] ?? 0)
            );
            $originalAmount = floatval($apiData['amount'] ?? 0);
            $status = $apiData['status'];

            // The debit already happened in reserve(). A success/pending row
            // shows that debit; an outright fail shows no net change because
            // VendorBase::process refunds the reservation right after this.
            $balanceBefore = (float) $reservation['balance_before'];
            $balanceAfter = $status === 'fail'
                ? $balanceBefore
                : (float) $reservation['balance_after'];

            Log::info("Transaction Recording", [
                'user_id' => $user->id,
                'original_amount' => $originalAmount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'promotion_id' => $promotionId,
                'status' => $status,
            ]);

            $tx_data = array_merge($apiData, [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'user_id' => $user->id,
                'amount' => $finalAmount,
                'discount_amount' => $discountAmount,
                'promotion_id' => $promotionId,
            ]);
            unset($tx_data['final_amount']);

            $transaction = Transaction::create($tx_data);

            // Rewards only on a confirmed-now success. A pending purchase
            // gets its rewards later, when the webhook settles it (see
            // awardForSettledTransaction / VendorBase::webhook).
            if ($status === 'success') {
                self::awardAll($user, $finalAmount, $transactionType);
            }

            AdminNotifier::notifyTransaction($transaction);

            $label = ucwords(str_replace('_', ' ', $transactionType));
            $user->notify(new AppNotification(
                $status === 'success' ? 'transaction_success' : 'transaction_failed',
                $status === 'success' ? "{$label} successful" : "{$label} failed",
                $status === 'success'
                    ? "Your {$label} purchase of ₦{$finalAmount} was successful. Ref: {$transaction->transaction_reference}"
                    : "Your {$label} purchase of ₦{$finalAmount} could not be completed.",
            ));

            SendTransactionCallback::dispatch($user, $transaction);

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

    /**
     * Rewards for a purchase confirmed AFTER the fact — an async vendor's
     * pending→success webhook. The funds were reserved at purchase time and
     * stay debited; this only pays out cashback/referral/events, which
     * record() would have done had the success been immediate. The caller
     * guards idempotency on the transaction's locked DB status.
     */
    public static function awardForSettledTransaction(User $user, float $amount, string $transactionType): void
    {
        self::awardAll($user, $amount, $transactionType);
    }

    /**
     * Cashback (flat per-service-type wallet credit), referral commission to
     * the referrer, and volume-based event badges — the full reward set for
     * a successful transaction, in one place so immediate and delayed
     * successes stay consistent.
     */
    protected static function awardAll(User $user, float $amount, string $transactionType): void
    {
        self::distributeCommission($user, $amount, $transactionType);
        self::creditCashback($user, $amount, $transactionType);
        EventService::checkAndAward($user);
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
