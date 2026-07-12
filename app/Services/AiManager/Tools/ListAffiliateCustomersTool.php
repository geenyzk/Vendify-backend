<?php

namespace App\Services\AiManager\Tools;

use App\Models\ChildCustomer;
use App\Models\User;

class ListAffiliateCustomersTool extends AiTool
{
    private const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'list_affiliate_customers';
    }

    public function description(): string
    {
        return 'List synced affiliate customers for a specific affiliate site, optionally filtering by email, username, or phone. Use this to discover affiliate customer ids before sending a direct message.';
    }

    public function permission(): ?string
    {
        return 'support';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'affiliate_id' => ['type' => 'integer', 'description' => 'Numeric id of the affiliate (child) instance.'],
                'query' => ['type' => 'string', 'description' => 'Optional filter term matched against email, username, or phone.'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows to return (1-50).'],
            ],
            'required' => ['affiliate_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'affiliate_id' => 'required|integer',
            'query' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $limit = min((int) ($arguments['limit'] ?? 25), self::MAX_LIMIT);

        $query = ChildCustomer::query()
            ->where('child_instance_id', $arguments['affiliate_id']);

        if (!empty($arguments['query'])) {
            $term = $arguments['query'];
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        $rows = $query->orderBy('id', 'desc')
            ->limit($limit)
            ->get(['id', 'external_id', 'username', 'email', 'phone', 'status', 'wallet_balance', 'created_at'])
            ->map(fn (ChildCustomer $customer) => [
                'id' => $customer->id,
                'external_id' => $customer->external_id,
                'username' => $customer->username,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'status' => $customer->status,
                'wallet_balance' => (float) $customer->wallet_balance,
                'synced_at' => optional($customer->created_at)->toDateTimeString(),
            ]);

        return [
            'returned' => $rows->count(),
            'customers' => $rows,
        ];
    }
}
