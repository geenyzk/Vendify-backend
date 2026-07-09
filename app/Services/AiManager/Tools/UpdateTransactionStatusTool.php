<?php

namespace App\Services\AiManager\Tools;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AiManager\AiManagerException;

/**
 * Override a transaction's status — mirrors TransactionController::updateStatus
 * (a refunded transaction's status is locked). Note: this only changes the
 * record; it does NOT move money. Use refund_transaction when the customer
 * should be credited. Mutating: proposal-only, re-checks `transactions`.
 */
class UpdateTransactionStatusTool extends AiTool
{
    public function name(): string
    {
        return 'update_transaction_status';
    }

    public function description(): string
    {
        return 'Propose overriding a transaction\'s status to pending, success, or fail. This only changes the record and moves NO money — to credit a customer use refund_transaction instead. A refunded transaction is locked and cannot be changed. Creates a pending action for admin approval.';
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
                'transaction_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'success', 'fail']],
                'note' => ['type' => 'string', 'description' => 'Reason, appended to the transaction message and audit trail.'],
            ],
            'required' => ['transaction_id', 'status'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'transaction_id' => 'required|integer',
            'status' => 'required|in:pending,success,fail',
            'note' => 'nullable|string|max:500',
        ];
    }

    public function summarize(array $arguments): string
    {
        $note = !empty($arguments['note']) ? " — {$arguments['note']}" : '';
        return "Set transaction #{$arguments['transaction_id']} status to '{$arguments['status']}'{$note}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $transaction = Transaction::find($arguments['transaction_id']);

        if (!$transaction) {
            throw new AiManagerException('Transaction not found.');
        }
        if ($transaction->refunded_at) {
            throw new AiManagerException('This transaction has already been refunded and its status is locked.');
        }

        $previous = $transaction->status;
        $transaction->status = $arguments['status'];

        $note = $arguments['note'] ?? null;
        $transaction->response_message = trim(
            ($transaction->response_message ? $transaction->response_message . ' | ' : '') .
            "Status set to {$arguments['status']} via AI Manager by {$actor->email}" .
            ($note ? ": {$note}" : '')
        );
        $transaction->save();

        return [
            'updated' => true,
            'transaction_id' => $transaction->id,
            'previous_status' => $previous,
            'new_status' => $transaction->status,
        ];
    }
}
