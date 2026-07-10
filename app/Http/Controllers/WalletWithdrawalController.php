<?php

namespace App\Http\Controllers;

use App\Classes\AdminNotifier;
use App\Classes\Payment\PaymentFactory;
use App\Classes\SerivceControl\ServiceControlService;
use App\Classes\TransactionService;
use App\HttpResponse;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletWithdrawal;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletWithdrawalController extends Controller
{
    use HttpResponse;

    /**
     * Live bank list from whichever active payment gateway actually
     * supports outbound transfers (currently only FlutterWave — see
     * PaymentFactory::makeTransferCapable()). Empty + available:false when
     * no such gateway is configured/active, so the frontend can disable the
     * form instead of letting a customer submit a request nothing can pay out.
     */
    public function banks(): JsonResponse
    {
        $gateway = PaymentFactory::makeTransferCapable();

        if (!$gateway) {
            return $this->success(['available' => false, 'banks' => []]);
        }

        $feeConfig = $gateway->withdrawalFeeConfig();

        return $this->success([
            'available' => true,
            'banks' => $gateway->getBanks(),
            'withdrawal_fee' => $feeConfig['fee'],
            'withdrawal_fee_type' => $feeConfig['type'],
        ]);
    }

    /**
     * Customer submits a withdrawal. The wallet is debited (reserved)
     * immediately so the same funds can't also be spent elsewhere while
     * this is pending — refunded automatically if rejected or if the
     * payout attempt fails. Whether that payout attempt happens now or
     * waits for an admin depends on Setting::wallet_withdrawal_auto_approve.
     */
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

        if (!ServiceControlService::verify($user->id, $validated['pin'])) {
            return $this->fail(['pin' => ['Invalid pin']], '', 422);
        }

        // The payout gateway's withdrawal fee is charged on TOP of the amount
        // the customer receives, so the wallet is debited amount + fee. The bank
        // still gets `amount`; the fee is recorded on the request so a later
        // refund (reject/failed payout) returns amount + fee.
        $amount = (float) $validated['amount'];
        $feeGateway = PaymentFactory::makeTransferCapable();
        $fee = $feeGateway ? $feeGateway->withdrawalFee($amount) : 0.0;
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
                'status' => 'pending',
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
        $withdrawals = WalletWithdrawal::where('user_id', Auth::id())->latest()->get();

        return $this->success($withdrawals);
    }

    public function adminIndex(): JsonResponse
    {
        $withdrawals = WalletWithdrawal::with(['user:id,username,email,phone', 'reviewer:id,username'])
            ->latest()
            ->get();

        return $this->success($withdrawals);
    }

    public function approve(WalletWithdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->status !== 'pending') {
            return $this->fail([], 'This request has already been reviewed.', 422);
        }

        $withdrawal->update(['reviewed_by' => Auth::id(), 'reviewed_at' => now()]);
        $this->processPayout($withdrawal);

        return $this->success($withdrawal->fresh(), 'Withdrawal processed');
    }

    public function reject(Request $request, WalletWithdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->status !== 'pending') {
            return $this->fail([], 'This request has already been reviewed.', 422);
        }

        $validated = $request->validate(['reason' => 'required|string|max:255']);

        DB::transaction(function () use ($withdrawal, $validated) {
            $user = User::findOrFail($withdrawal->user_id);
            // Refund the full debit: what they'd have received + the fee charged.
            TransactionService::fundUser(
                $user,
                (float) $withdrawal->amount + (float) $withdrawal->fee,
                'credit',
                "Withdrawal rejected: {$validated['reason']}",
                'wallet_withdrawal',
                'wallet',
                $withdrawal->account_number,
                $withdrawal->transaction_reference,
            );

            $withdrawal->update([
                'status' => 'rejected',
                'rejection_reason' => $validated['reason'],
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);

            $user->notify(new AppNotification(
                'wallet_withdrawal_rejected',
                'Withdrawal rejected',
                "Your ₦{$withdrawal->amount} withdrawal was rejected and refunded: {$validated['reason']}",
            ));
        });

        return $this->success($withdrawal->fresh(), 'Withdrawal rejected and wallet refunded');
    }

    /**
     * Attempts the actual gateway payout for an already-debited withdrawal.
     * On failure, refunds the reserved amount back so the customer isn't
     * left short — shared by both the auto-approve path and the admin
     * approve() action.
     */
    private function processPayout(WalletWithdrawal $withdrawal): void
    {
        $gateway = PaymentFactory::makeTransferCapable();

        if (!$gateway) {
            $this->failPayout($withdrawal, 'No payout gateway is currently available.');
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
            Log::error('Wallet withdrawal payout threw', ['id' => $withdrawal->id, 'error' => $e->getMessage()]);
            $this->failPayout($withdrawal, $e->getMessage());
            return;
        }

        if (($response['status'] ?? 'failed') === 'success') {
            $withdrawal->update([
                'status' => 'completed',
                'gateway_reference' => $response['data']['reference'] ?? $response['data']['id'] ?? null,
            ]);

            User::find($withdrawal->user_id)?->notify(new AppNotification(
                'wallet_withdrawal_completed',
                'Withdrawal sent',
                "₦{$withdrawal->amount} was sent to your {$withdrawal->bank_name} account.",
            ));
            return;
        }

        $this->failPayout($withdrawal, $response['message'] ?? 'Payout failed');
    }

    private function failPayout(WalletWithdrawal $withdrawal, string $reason): void
    {
        DB::transaction(function () use ($withdrawal, $reason) {
            $user = User::findOrFail($withdrawal->user_id);
            // Refund the full debit: the payout amount plus the fee charged.
            TransactionService::fundUser(
                $user,
                (float) $withdrawal->amount + (float) $withdrawal->fee,
                'credit',
                "Withdrawal payout failed, refunded: {$reason}",
                'wallet_withdrawal',
                'wallet',
                $withdrawal->account_number,
                $withdrawal->transaction_reference,
            );

            $withdrawal->update([
                'status' => 'failed',
                'rejection_reason' => $reason,
            ]);

            $user->notify(new AppNotification(
                'wallet_withdrawal_failed',
                'Withdrawal failed',
                "Your ₦{$withdrawal->amount} withdrawal could not be completed and has been refunded to your wallet.",
            ));
        });
    }
}
