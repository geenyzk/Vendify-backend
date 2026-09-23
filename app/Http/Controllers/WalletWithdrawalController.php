<?php

namespace App\Http\Controllers;

use App\Classes\AdminNotifier;
use App\Classes\Payment\PaymentFactory;
use App\Classes\SerivceControl\ServiceControlService;
use App\Classes\TransactionService;
use App\HttpResponse;
use App\Models\Setting;
use App\Models\WalletWithdrawal;
use App\Services\Payments\WalletWithdrawalSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletWithdrawalController extends Controller
{
    use HttpResponse;

    public function banks(): JsonResponse
    {
        $settings = Setting::first();
        $min = (float) ($settings?->wallet_withdrawal_min ?? 500);
        $max = (float) ($settings?->wallet_withdrawal_max ?? 500000);
        $gateway = PaymentFactory::makeTransferCapable();

        if (!$gateway) {
            return $this->success([
                'available' => false,
                'banks' => [],
                'minimum' => $min,
                'maximum' => $max,
            ]);
        }

        $feeConfig = $gateway->withdrawalFeeConfig();

        return $this->success([
            'available' => true,
            'banks' => $gateway->getBanks(),
            'minimum' => $min,
            'maximum' => $max,
            'withdrawal_fee' => $feeConfig['fee'],
            'withdrawal_fee_type' => $feeConfig['type'],
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $settings = Setting::first();
        $min = (float) ($settings?->wallet_withdrawal_min ?? 500);
        $max = (float) ($settings?->wallet_withdrawal_max ?? 500000);

        $validated = $request->validate([
            'amount' => "required|numeric|min:{$min}|max:{$max}",
            'bank_code' => 'required|string',
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
            'account_name' => 'required|string',
            'pin' => 'required|string',
        ]);

        $user = Auth::user();
        if (!ServiceControlService::verifyTransactionPin($user->id, $validated['pin'])) {
            return $this->fail(['pin' => ['Invalid pin']], '', 422);
        }

        $amount = (float) $validated['amount'];
        $feeGateway = PaymentFactory::makeTransferCapable();
        if (!$feeGateway) {
            return $this->fail([], 'Bank withdrawals are currently unavailable.', 503);
        }
        $fee = $feeGateway->withdrawalFee($amount);
        $total = $amount + $fee;

        if ((float) $user->wallet_balance < $total) {
            return $this->fail([], 'Insufficient wallet balance for the amount plus fee.', 422);
        }

        $withdrawal = DB::transaction(function () use ($user, $validated, $amount, $fee, $total) {
            $reservation = TransactionService::fundUser(
                $user,
                $total,
                'debit',
                'Wallet withdrawal to ' . $validated['bank_name'] . ' ' . $validated['account_number'],
                'wallet_withdrawal',
                'wallet',
                $validated['account_number'],
            );

            return WalletWithdrawal::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'fee' => $fee,
                'bank_code' => $validated['bank_code'],
                'bank_name' => $validated['bank_name'],
                'account_number' => $validated['account_number'],
                'account_name' => $validated['account_name'],
                'status' => WalletWithdrawal::STATUS_PENDING,
                'transaction_reference' => $reservation['transaction_reference'],
            ]);
        });

        if ($settings?->wallet_withdrawal_auto_approve) {
            $this->processPayout($withdrawal);
            $withdrawal->refresh();
        } else {
            AdminNotifier::notifyWalletWithdrawalPending($withdrawal);
        }

        return $this->success($withdrawal, 'Withdrawal request submitted', 201);
    }

    public function myRequests(): JsonResponse
    {
        return $this->success(WalletWithdrawal::where('user_id', Auth::id())->latest()->get());
    }

    public function adminIndex(): JsonResponse
    {
        return $this->success(WalletWithdrawal::with(['user:id,username,email,phone', 'reviewer:id,username'])
            ->latest()
            ->get());
    }

    public function approve(WalletWithdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->status !== WalletWithdrawal::STATUS_PENDING) {
            return $this->fail([], 'This request has already been reviewed.', 422);
        }

        $withdrawal->update(['reviewed_by' => Auth::id(), 'reviewed_at' => now()]);
        $this->processPayout($withdrawal);

        return $this->success($withdrawal->fresh(), 'Withdrawal submitted for processing');
    }

    public function reject(Request $request, WalletWithdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->status !== WalletWithdrawal::STATUS_PENDING) {
            return $this->fail([], 'This request has already been reviewed.', 422);
        }

        $validated = $request->validate(['reason' => 'required|string|max:255']);
        DB::transaction(function () use ($withdrawal, $validated) {
            $withdrawal->update([
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);
            app(WalletWithdrawalSettlementService::class)->markFailed(
                $withdrawal,
                $validated['reason'],
                WalletWithdrawal::STATUS_REJECTED,
            );
        });

        return $this->success($withdrawal->fresh(), 'Withdrawal rejected and wallet refunded');
    }

    /** Start a payout without treating a queued response as completion. */
    private function processPayout(WalletWithdrawal $withdrawal): void
    {
        $gateway = PaymentFactory::makeTransferCapable();
        if (!$gateway) {
            app(WalletWithdrawalSettlementService::class)->markFailed(
                $withdrawal,
                'No payout gateway is currently available.',
            );
            return;
        }

        try {
            $response = $gateway->transfer([
                'account_bank' => $withdrawal->bank_code,
                'account_number' => $withdrawal->account_number,
                'amount' => (float) $withdrawal->amount,
                'narration' => 'Wallet withdrawal',
                'reference' => $withdrawal->transaction_reference,
            ]);
        } catch (\Throwable $e) {
            // The remote request may have succeeded before a timeout. Preserve
            // the reservation and reconcile by our unique reference.
            Log::error('Wallet withdrawal initiation is ambiguous.', [
                'id' => $withdrawal->id,
                'reference' => $withdrawal->transaction_reference,
                'error_category' => class_basename($e),
            ]);
            $withdrawal->update([
                'status' => WalletWithdrawal::STATUS_PROCESSING,
                'rejection_reason' => 'Transfer initiation could not be confirmed; reconciliation is pending.',
            ]);
            return;
        }

        if (($response['status'] ?? 'failed') === 'processing') {
            $withdrawal->update([
                'status' => WalletWithdrawal::STATUS_PROCESSING,
                'gateway_transfer_id' => isset($response['data']['id']) ? (string) $response['data']['id'] : null,
                'gateway_reference' => $response['data']['reference'] ?? $withdrawal->transaction_reference,
                'rejection_reason' => null,
            ]);
            return;
        }

        app(WalletWithdrawalSettlementService::class)->markFailed(
            $withdrawal,
            $response['message'] ?? 'Payout was rejected.',
        );
    }
}
