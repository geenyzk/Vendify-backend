<?php

namespace App\Services\AiManager\Tools;

use App\Classes\TransactionService;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AiManager\AiManagerException;
use Illuminate\Support\Facades\DB;

/**
 * Refund a successful, wallet-charging transaction — mirrors
 * TransactionController::refund exactly (same guards, same money primitive
 * TransactionService::fundUser, same "lock as refunded" step). Mutating:
 * only runs after an admin approves the proposal, and approval re-checks the
 * `transactions` permission.
 */
class RefundTransactionTool extends AiTool
{
    public function name(): string
    {
        return 'refund_transaction';
    }

    public function description(): string
    {
        return 'Propose refunding a transaction: credits the customer\'s wallet with the transaction amount and locks the original as refunded. Only successful, refundable, not-already-refunded transactions can be refunded. This does NOT execute immediately — it creates a pending action an admin must approve.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'transactions';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'transaction_id' => ['type' => 'integer', 'description' => 'Numeric id of the transaction to refund.'],
                'reason' => ['type' => 'string', 'description' => 'Why the refund is being issued (shown on the transaction and audit trail).'],
            ],
            'required' => ['transaction_id', 'reason'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'transaction_id' => 'required|integer',
            'reason' => 'required|string|max:500',
        ];
    }

    public function summarize(array $arguments): string
    {
        return "Refund transaction #{$arguments['transaction_id']} (credit the customer's wallet) — reason: {$arguments['reason']}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $transaction = Transaction::with('user')->find($arguments['transaction_id']);

        if (!$transaction) {
            throw new AiManagerException('Transaction not found.');
        }
        if (!$transaction->user) {
            throw new AiManagerException('This transaction has no associated user to refund.');
        }
        if (!in_array($transaction->transaction_type, Transaction::REFUNDABLE_TYPES, true)) {
            throw new AiManagerException('This transaction type cannot be refunded.');
        }
        if ($transaction->status !== 'success') {
            throw new AiManagerException('Only a successful transaction can be refunded.');
        }
        if ($transaction->refunded_at) {
            throw new AiManagerException('This transaction has already been refunded.');
        }

        DB::transaction(function () use ($transaction, $arguments, $actor) {
            TransactionService::fundUser(
                $transaction->user,
                (float) $transaction->amount,
                'credit',
                "Refund for {$transaction->transaction_reference}: {$arguments['reason']}"
            );

            $transaction->status = 'fail';
            $transaction->refunded_at = now();
            $transaction->refund_reason = $arguments['reason'];
            $transaction->response_message = trim(
                ($transaction->response_message ? $transaction->response_message . ' | ' : '') .
                "Refunded via AI Manager by {$actor->email}: {$arguments['reason']}"
            );
            $transaction->save();
        });

        $transaction->refresh();

        return [
            'refunded' => true,
            'transaction_id' => $transaction->id,
            'amount_credited' => (float) $transaction->amount,
            'customer_new_balance' => (float) $transaction->user->fresh()->wallet_balance,
        ];
    }
}
