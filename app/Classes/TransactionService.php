<?php

namespace App\Classes;

use App\Jobs\SendTransactionCallback;
use App\Models\Transaction;
use App\Models\User;
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

            $discounted_amount = floatval($apiData['amount']) - floatval($apiData['discount_amount']);
            // $amount = floatval($apiData['amount']);
            $balanceBefore = floatval($user->wallet_balance);
            $balanceAfter = $apiData["status"] === "success"? $balanceBefore - $discounted_amount: $user->wallet_balance;

            // Optional: Deduct wallet (if not done via API balance already)
            $user->wallet_balance = $balanceAfter;
            $user->save();

            // Create the transaction
            $tx_data = array_merge($apiData, [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'user_id' => $user->id,
                "amount" => $discounted_amount,
            ]);
            Log::info(['merged_tx' => $tx_data, "unmerged_tx" =>  [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'user_id' => $user->id,
                "amount" => $discounted_amount,
            ]]);
            $transaction = Transaction::create($tx_data);

            // Optional: Commission distribution
            self::distributeCommission($user, $discounted_amount, $transactionType);

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

            // Optionally log it or create a commission record
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
            "receiver" => $user->username,
        ]);

        return $transaction->toArray();
    });
}

}
