<?php

namespace App\Services\AiManager\Tools;

use App\Models\DataPlan;
use App\Models\Role;
use App\Models\User;

class GetPricingStrategyTool extends AiTool
{
    private const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'get_pricing_strategy';
    }

    public function description(): string
    {
        return 'Review data plan pricing strategy across the catalog for a given role, network, or plan type. Returns the current pricing mix, typical markup ranges, and a short strategy summary.';
    }

    public function permission(): ?string
    {
        return 'settings';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Optional data plan id to inspect a single plan.'],
                'role' => ['type' => 'string', 'description' => 'Customer role to evaluate pricing for, default is user.'],
                'network' => ['type' => 'string', 'description' => 'Optional network filter.'],
                'plan_type' => ['type' => 'string', 'description' => 'Optional plan type filter.'],
                'active_only' => ['type' => 'boolean', 'description' => 'Only include active plans. Default true.'],
                'limit' => ['type' => 'integer', 'description' => 'Max number of plans to analyze (1-50).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'plan_id' => 'nullable|integer',
            'role' => 'nullable|string|max:60',
            'network' => 'nullable|string|max:40',
            'plan_type' => 'nullable|string|max:40',
            'active_only' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $query = DataPlan::query();

        if (!empty($arguments['plan_id'])) {
            $query->where('id', $arguments['plan_id']);
        }
        if (!empty($arguments['network'])) {
            $query->where('network', strtolower($arguments['network']));
        }
        if (!empty($arguments['plan_type'])) {
            $query->where('plan_type', $arguments['plan_type']);
        }
        if (!empty($arguments['active_only'])) {
            $query->where('active', true);
        }

        $limit = min((int) ($arguments['limit'] ?? 15), self::MAX_LIMIT);
        $plans = $query->orderBy('network')->orderBy('id')->limit($limit)->get();
        $role = $arguments['role'] ?? 'user';

        if ($role !== 'user') {
            $normalized = $this->resolveRoleName($role);
            if (!$normalized) {
                return [
                    'role' => $role,
                    'plan_count' => 0,
                    'strategy_summary' => "Role '{$role}' does not exist; use a valid customer role name or slug.",
                    'plans' => [],
                ];
            }
            $role = $normalized;
        }

        $totals = [
            'fiat' => 0,
            'percentage' => 0,
            'fallback' => 0,
            'plans' => [],
        ];

        foreach ($plans as $plan) {
            $strategy = $this->planPricingStrategy($plan, $role);
            $totals[$strategy['pricing_type']]++;
            $totals['plans'][] = $strategy;
        }

        $strategySummary = $this->summarizeStrategy($totals, $role);

        return [
            'role' => $role,
            'plan_count' => $plans->count(),
            'strategy_summary' => $strategySummary,
            'plans' => $totals['plans'],
        ];
    }

    private function planPricingStrategy(DataPlan $plan, string $role): array
    {
        $pricingConfig = $plan->pricing[$role] ?? null;
        $cost = $plan->resolveCostPrice();
        $price = null;
        $pricingType = 'fallback';

        if (is_array($pricingConfig)) {
            $pricingType = $pricingConfig['type'] ?? 'fiat';
            if ($pricingType === 'percentage') {
                $price = round($cost * (1 + (float) ($pricingConfig['value'] ?? 0) / 100), 2);
            } else {
                $price = (float) ($pricingConfig['value'] ?? 0);
            }
        } elseif ($pricingConfig !== null) {
            $pricingType = 'fiat';
            $price = (float) $pricingConfig;
        } else {
            $price = (float) $plan->price;
        }

        $markup = $cost > 0 ? round((($price - $cost) / $cost) * 100, 2) : null;

        return [
            'plan_id' => $plan->id,
            'network' => $plan->network,
            'plan_name' => $plan->plan_name,
            'plan_size' => $plan->plan_size,
            'plan_type' => $plan->plan_type,
            'active' => (bool) $plan->active,
            'pricing_type' => $pricingType,
            'price' => $price,
            'cost_price' => $cost,
            'markup_percentage' => $markup,
        ];
    }

    private function resolveRoleName(string $role): ?string
    {
        $roleModel = Role::where('slug', $role)->orWhere('name', $role)->first();
        return $roleModel?->name;
    }

    private function summarizeStrategy(array $totals, string $role): string
    {
        $planCount = count($totals['plans']);
        if ($planCount === 0) {
            return "No plans found for role {$role} with the selected filters.";
        }

        $strategyParts = [];
        if ($totals['fiat'] > 0) {
            $strategyParts[] = "{$totals['fiat']} plan(s) use fixed fiat pricing.";
        }
        if ($totals['percentage'] > 0) {
            $strategyParts[] = "{$totals['percentage']} plan(s) use percentage markups over cost.";
        }
        if ($totals['fallback'] > 0) {
            $strategyParts[] = "{$totals['fallback']} plan(s) are using fallback pricing based on the plan's own price accessor.";
        }

        $summary = implode(' ', $strategyParts);
        $summary .= ' Use fixed pricing for stable bundles and percentage markup pricing for plans where cost can vary or should track provider cost changes.';

        return $summary;
    }
}
