<?php

namespace App\Services\Payments;

use App\Classes\TransactionService;
use App\Models\User;
use App\Models\WalletWithdrawal;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\DB;

class WalletWithdrawalSettlementService
{
    public function markSuccessful(WalletWithdrawal $withdrawal, ?string $gatewayTransferId = null, ?string $gatewayReference = null): void
    {
        DB::transaction(function () use ($withdrawal, $gatewayTransferId, $gatewayReference) {
            $locked = WalletWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === WalletWithdrawal::STATUS_SUCCESSFUL) {
                return;
            }
            if (in_array($locked->status, [WalletWithdrawal::STATUS_FAILED, WalletWithdrawal::STATUS_REJECTED], true)) {
                return;
            }

            $locked->update([
                'status' => WalletWithdrawal::STATUS_SUCCESSFUL,
                'gateway_transfer_id' => $gatewayTransferId ?: $locked->gateway_transfer_id,
                'gateway_reference' => $gatewayReference ?: $locked->gateway_reference,
                'completed_at' => now(),
                'rejection_reason' => null,
            ]);

            DB::afterCommit(function () use ($locked) {
                User::find($locked->user_id)?->notify(new AppNotification(
                    'wallet_withdrawal_completed',
                    'Withdrawal sent',
                    "₦{$locked->amount} was sent to your {$locked->bank_name} account.",
                ));
            });
        });
    }

    public function markFailed(WalletWithdrawal $withdrawal, string $reason, string $status = WalletWithdrawal::STATUS_FAILED): void
    {
        DB::transaction(function () use ($withdrawal, $reason, $status) {
            $locked = WalletWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === WalletWithdrawal::STATUS_SUCCESSFUL || $locked->refunded_at !== null) {
                return;
            }

            $user = User::whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            TransactionService::fundUser(
                $user,
                (float) $locked->amount + (float) $locked->fee,
                'credit',
                "Withdrawal {$status}: {$reason}",
                'wallet_withdrawal',
                'wallet',
                $locked->account_number,
                $locked->transaction_reference,
            );

            $locked->update([
                'status' => $status,
                'rejection_reason' => $reason,
                'refunded_at' => now(),
            ]);

            DB::afterCommit(function () use ($locked, $status, $reason) {
                $user = User::find($locked->user_id);
                if (!$user) {
                    return;
                }
                $rejected = $status === WalletWithdrawal::STATUS_REJECTED;
                $user->notify(new AppNotification(
                    $rejected ? 'wallet_withdrawal_rejected' : 'wallet_withdrawal_failed',
                    $rejected ? 'Withdrawal rejected' : 'Withdrawal failed',
                    $rejected
                        ? "Your ₦{$locked->amount} withdrawal was rejected and refunded: {$reason}"
                        : "Your ₦{$locked->amount} withdrawal could not be completed and has been refunded to your wallet.",
                ));
            });
        });
    }
}
