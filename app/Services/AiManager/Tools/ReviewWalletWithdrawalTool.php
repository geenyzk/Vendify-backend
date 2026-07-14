<?php

namespace App\Services\AiManager\Tools;

use App\Http\Controllers\WalletWithdrawalController;
use App\Models\User;
use App\Models\WalletWithdrawal;
use App\Services\AiManager\AiManagerException;
use App\Services\AiManager\Tools\Concerns\CallsControllerAction;
use Illuminate\Http\Request;

/**
 * Approve (pay out) or reject (refund) a pending wallet withdrawal. Mutating:
 * proposal-only, gated by `wallets`. Delegates to WalletWithdrawalController so
 * the real gateway payout, wallet refund and customer notifications are reused
 * exactly, never re-implemented here.
 */
class ReviewWalletWithdrawalTool extends AiTool
{
    use CallsControllerAction;

    public function name(): string
    {
        return 'review_wallet_withdrawal';
    }

    public function description(): string
    {
        return 'Approve or reject a pending wallet withdrawal (payout). "approve" attempts the bank payout via the gateway; "reject" refunds the reserved amount (plus fee) to the customer wallet and requires a reason. Look up the id and confirm it is still pending with list_wallet_withdrawals first. Creates a pending action an admin must approve.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'wallets';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'withdrawal_id' => ['type' => 'integer', 'description' => 'Id of the withdrawal request.'],
                'action' => ['type' => 'string', 'enum' => ['approve', 'reject'], 'description' => 'approve = pay out; reject = refund.'],
                'reason' => ['type' => 'string', 'description' => 'Required when rejecting: why it was rejected (shown to the customer).'],
            ],
            'required' => ['withdrawal_id', 'action'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'withdrawal_id' => 'required|integer',
            'action' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:255|required_if:action,reject',
        ];
    }

    public function summarize(array $arguments): string
    {
        $id = $arguments['withdrawal_id'];

        return $arguments['action'] === 'approve'
            ? "Approve and pay out wallet withdrawal #{$id}"
            : "Reject wallet withdrawal #{$id} and refund the customer (reason: " . ($arguments['reason'] ?? '—') . ')';
    }

    public function handle(array $arguments, User $actor): array
    {
        $withdrawal = WalletWithdrawal::find($arguments['withdrawal_id']);
        if (!$withdrawal) {
            throw new AiManagerException('Withdrawal request not found.');
        }

        $controller = app(WalletWithdrawalController::class);

        if ($arguments['action'] === 'approve') {
            return $this->unwrap($controller->approve($withdrawal), 'The withdrawal could not be paid out.');
        }

        $request = Request::create('/', 'POST', ['reason' => $arguments['reason'] ?? '']);

        return $this->unwrap($controller->reject($request, $withdrawal), 'The withdrawal could not be rejected.');
    }
}
