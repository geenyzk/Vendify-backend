<?php

namespace App\Services\AiManager\Tools;

use App\Models\Transaction;
use App\Models\User;

/**
 * Full detail for a single transaction, by numeric id or by reference. Used
 * after search_transactions narrows things down, or when an admin quotes a
 * reference directly.
 */
class GetTransactionTool extends AiTool
{
    public function name(): string
    {
        return 'get_transaction';
    }

    public function description(): string
    {
        return 'Fetch the full detail of one transaction by its numeric id or its transaction/payment reference, including the owning customer and refund state. Use this to diagnose a specific transaction before proposing a refund or status change.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Numeric transaction id.'],
                'reference' => ['type' => 'string', 'description' => 'Exact transaction or payment reference.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'id' => 'nullable|integer',
            'reference' => 'nullable|string|max:120',
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $query = Transaction::with('user:id,fullname,email,phone,wallet_balance');

        if (!empty($arguments['id'])) {
            $query->where('id', $arguments['id']);
        } elseif (!empty($arguments['reference'])) {
            $ref = $arguments['reference'];
            $query->where(function ($q) use ($ref) {
                $q->where('transaction_reference', $ref)->orWhere('payment_reference', $ref);
            });
        } else {
            return ['error' => 'Provide either id or reference.'];
        }

        $transaction = $query->first();

        if (!$transaction) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'transaction' => [
                'id' => $transaction->id,
                'type' => $transaction->transaction_type,
                'provider' => $transaction->provider,
                'recipient' => $transaction->account_or_phone,
                'amount' => (float) $transaction->amount,
                'service_fee' => (float) $transaction->service_fee,
                'status' => $transaction->status,
                'funding_method' => $transaction->funding_method,
                'balance_before' => $transaction->balance_before,
                'balance_after' => $transaction->balance_after,
                'transaction_reference' => $transaction->transaction_reference,
                'payment_reference' => $transaction->payment_reference,
                'response_message' => $transaction->response_message,
                'refunded_at' => optional($transaction->refunded_at)->toDateTimeString(),
                'refund_reason' => $transaction->refund_reason,
                'created_at' => optional($transaction->created_at)->toDateTimeString(),
                'completed_at' => optional($transaction->completed_at)->toDateTimeString(),
                'refundable_types' => Transaction::REFUNDABLE_TYPES,
            ],
            'customer' => $transaction->user ? [
                'id' => $transaction->user->id,
                'name' => $transaction->user->fullname,
                'email' => $transaction->user->email,
                'phone' => $transaction->user->phone,
                'wallet_balance' => (float) $transaction->user->wallet_balance,
            ] : null,
        ];
    }
}
