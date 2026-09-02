<?php

namespace App\Services;

use App\Classes\TransactionService;
use App\Models\AirtimeToCashRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionPlatform;
use DomainException;
use Illuminate\Support\Facades\DB;

class AirtimeToCashSettlementService
{
    /**
     * Atomically make the one wallet payout allowed for a conversion.
     *
     * The existing pending/approved/rejected state model is deliberately
     * retained. Future provider states can enter this same settlement boundary
     * once they are eligible to become approved/completed.
     */
    public function settle(int $requestId, string $reviewerId): AirtimeToCashRequest
    {
        return DB::transaction(function () use ($requestId, $reviewerId): AirtimeToCashRequest {
            $request = AirtimeToCashRequest::query()->lockForUpdate()->findOrFail($requestId);

            if ($request->status !== 'pending') {
                throw new DomainException('This request has already been reviewed.');
            }

            if ($request->payoutTransaction()->exists()
                || ($request->payout_transaction_reference
                    && Transaction::where('transaction_reference', $request->payout_transaction_reference)->exists())) {
                throw new DomainException('This request already has a payout transaction.');
            }

            $user = User::query()->lockForUpdate()->findOrFail($request->user_id);
            $amount = (float) $request->payout_amount;
            $balanceBefore = (float) $user->wallet_balance;
            $balanceAfter = $balanceBefore + $amount;

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'transaction_type' => Transaction::TYPE_AIRTIME_TO_CASH,
                'provider' => 'admin',
                'account_or_phone' => $user->phone,
                'amount' => $amount,
                'plan_type' => 'credit',
                'status' => 'success',
                'transaction_reference' => TransactionService::generateTransactionReference(),
                'related_reference' => $request->transaction_reference,
                'airtime_to_cash_request_id' => $request->id,
                'funding_method' => 'manual',
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'completed_at' => now(),
                'response_message' => 'Airtime to cash - '.strtoupper($request->network).' - '.$request->sender_phone,
                'platform' => TransactionPlatform::current(),
                'receiver' => $user->username,
            ]);

            $user->increment('wallet_balance', $amount);

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'payout_transaction_reference' => $transaction->transaction_reference,
            ]);

            return $request->fresh(['payoutTransaction']);
        });
    }

}
