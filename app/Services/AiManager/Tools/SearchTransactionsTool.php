<?php

namespace App\Services\AiManager\Tools;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Filtered lookup over transactions — the workhorse for investigating failures,
 * a customer's activity, provider issues, or volume in a window. Returns a
 * capped, compact page of rows plus the total match count so the model knows
 * whether it is seeing everything.
 */
class SearchTransactionsTool extends AiTool
{
    private const MAX_LIMIT = 25;

    public function name(): string
    {
        return 'search_transactions';
    }

    public function description(): string
    {
        return 'Search transactions with optional filters. Use it to investigate failures, a customer\'s history, a specific provider, or volume in a date window. Returns a capped list of matching transactions (most recent first) and the total number of matches. To inspect one transaction in full, use get_transaction.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['success', 'pending', 'fail'],
                    'description' => 'Only transactions with this status.',
                ],
                'transaction_type' => [
                    'type' => 'string',
                    'description' => 'e.g. airtime_recharge, data_subscription, cable_subscription, electric_bill, wallet_funding.',
                ],
                'provider' => ['type' => 'string', 'description' => 'Vendor/provider code, e.g. sme_plug.'],
                'user_id' => ['type' => 'integer', 'description' => 'Restrict to one customer by id.'],
                'user_email' => ['type' => 'string', 'description' => 'Restrict to one customer by email.'],
                'reference' => ['type' => 'string', 'description' => 'Match a transaction or payment reference (partial allowed).'],
                'start_date' => ['type' => 'string', 'description' => 'ISO date/datetime lower bound (inclusive).'],
                'end_date' => ['type' => 'string', 'description' => 'ISO date/datetime upper bound (inclusive).'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows to return (1-25, default 10).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'status' => 'nullable|in:success,pending,fail',
            'transaction_type' => 'nullable|string|max:60',
            'provider' => 'nullable|string|max:60',
            'user_id' => 'nullable|integer',
            'user_email' => 'nullable|email',
            'reference' => 'nullable|string|max:120',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $query = Transaction::query();

        if (!empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }
        if (!empty($arguments['transaction_type'])) {
            $query->where('transaction_type', $arguments['transaction_type']);
        }
        if (!empty($arguments['provider'])) {
            $query->where('provider', $arguments['provider']);
        }
        if (!empty($arguments['user_id'])) {
            $query->where('user_id', $arguments['user_id']);
        }
        if (!empty($arguments['user_email'])) {
            $userId = User::where('email', $arguments['user_email'])->value('id');
            // No such user => guarantee an empty result rather than ignoring
            // the filter and returning everyone's transactions.
            $query->where('user_id', $userId ?? 0);
        }
        if (!empty($arguments['reference'])) {
            $ref = $arguments['reference'];
            $query->where(function ($q) use ($ref) {
                $q->where('transaction_reference', 'like', "%{$ref}%")
                    ->orWhere('payment_reference', 'like', "%{$ref}%");
            });
        }
        if (!empty($arguments['start_date'])) {
            $query->where('created_at', '>=', Carbon::parse($arguments['start_date']));
        }
        if (!empty($arguments['end_date'])) {
            $query->where('created_at', '<=', Carbon::parse($arguments['end_date']));
        }

        $total = (clone $query)->count();
        $limit = min((int) ($arguments['limit'] ?? 10), self::MAX_LIMIT);

        $rows = $query->latest('id')
            ->limit($limit)
            ->get([
                'id', 'user_id', 'transaction_type', 'provider', 'account_or_phone',
                'amount', 'status', 'transaction_reference', 'response_message', 'created_at',
            ])
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'user_id' => $t->user_id,
                'type' => $t->transaction_type,
                'provider' => $t->provider,
                'recipient' => $t->account_or_phone,
                'amount' => (float) $t->amount,
                'status' => $t->status,
                'reference' => $t->transaction_reference,
                'message' => $t->response_message,
                'created_at' => optional($t->created_at)->toDateTimeString(),
            ]);

        return [
            'total_matches' => $total,
            'returned' => $rows->count(),
            'transactions' => $rows,
        ];
    }
}
