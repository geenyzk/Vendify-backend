<?php

namespace App\Services\AiManager\Tools;

use App\Models\DataPlan;
use App\Models\User;

/**
 * Look up data plans and their current per-role pricing so the assistant can
 * identify the right plan id before proposing a price change. Returns the raw
 * `pricing` map (role => {type, value}) rather than the single viewer-scoped
 * price accessor, since that is what update_data_plan_price edits.
 */
class SearchDataPlansTool extends AiTool
{
    private const MAX_LIMIT = 30;

    public function name(): string
    {
        return 'search_data_plans';
    }

    public function description(): string
    {
        return 'Search data plans by network, plan type, or name. Returns id, name, size, network, active flag, and the current per-role pricing map. Use this to find the plan id and see existing prices before proposing a price change with update_data_plan_price.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'network' => ['type' => 'string', 'description' => 'e.g. mtn, glo, airtel, 9mobile.'],
                'plan_type' => ['type' => 'string', 'description' => 'e.g. SME, GIFTING, CORPORATE.'],
                'query' => ['type' => 'string', 'description' => 'Match against the plan name.'],
                'active_only' => ['type' => 'boolean', 'description' => 'Only active plans. Default false (all).'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (1-30, default 15).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'network' => 'nullable|string|max:40',
            'plan_type' => 'nullable|string|max:40',
            'query' => 'nullable|string|max:120',
            'active_only' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $query = DataPlan::query();

        if (!empty($arguments['network'])) {
            $query->where('network', strtolower($arguments['network']));
        }
        if (!empty($arguments['plan_type'])) {
            $query->where('plan_type', $arguments['plan_type']);
        }
        if (!empty($arguments['query'])) {
            $query->where('plan_name', 'like', "%{$arguments['query']}%");
        }
        if (!empty($arguments['active_only'])) {
            $query->where('active', true);
        }

        $total = (clone $query)->count();
        $limit = min((int) ($arguments['limit'] ?? 15), self::MAX_LIMIT);

        $rows = $query->orderBy('network')->orderBy('id')
            ->limit($limit)
            ->get(['id', 'plan_name', 'plan_size', 'plan_type', 'network', 'validity', 'active', 'pricing'])
            ->map(fn (DataPlan $p) => [
                'id' => $p->id,
                'name' => $p->plan_name,
                'size' => $p->plan_size,
                'type' => $p->plan_type,
                'network' => $p->network,
                'validity' => $p->validity,
                'active' => (bool) $p->active,
                'pricing' => $p->pricing,
            ]);

        return [
            'total_matches' => $total,
            'returned' => $rows->count(),
            'pricing_note' => 'pricing maps a role name to {"type":"fiat"|"percentage","value":number}. A fiat value is the exact price; a percentage is a markup over the provider cost price.',
            'plans' => $rows,
        ];
    }
}
