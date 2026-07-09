<?php

namespace App\Services\AiManager\Tools;

use App\Models\User;

/**
 * Find customers by a free-text term across name, email, phone and username.
 * Deliberately returns only compact identity + wallet fields (never the heavy
 * serialized User with its stats/transactions appends).
 */
class SearchUsersTool extends AiTool
{
    private const MAX_LIMIT = 25;

    public function name(): string
    {
        return 'search_users';
    }

    public function description(): string
    {
        return 'Search customers by name, email, phone, or username. Returns compact matches (id, name, email, phone, status, wallet balance). Use get_user for a full profile of one customer.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Term to match across name, email, phone, username.'],
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'banned', 'suspended'],
                    'description' => 'Optionally restrict to a status.',
                ],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (1-25, default 10).'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'query' => 'required|string|max:120',
            'status' => 'nullable|in:active,banned,suspended',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $term = $arguments['query'];
        $limit = min((int) ($arguments['limit'] ?? 10), self::MAX_LIMIT);

        $query = User::query()->where(function ($q) use ($term) {
            $q->where('fullname', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('username', 'like', "%{$term}%");
        });

        if (!empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        $total = (clone $query)->count();

        $rows = $query->latest('id')
            ->limit($limit)
            ->get(['id', 'fullname', 'email', 'phone', 'username', 'status', 'wallet_balance', 'created_at'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->fullname,
                'email' => $u->email,
                'phone' => $u->phone,
                'username' => $u->username,
                'status' => $u->status,
                'wallet_balance' => (float) $u->wallet_balance,
                'joined' => optional($u->created_at)->toDateString(),
            ]);

        return [
            'total_matches' => $total,
            'returned' => $rows->count(),
            'users' => $rows,
        ];
    }
}
