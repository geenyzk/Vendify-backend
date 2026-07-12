<?php

namespace App\Services\AiManager\Tools;

use App\Models\DataPlan;
use App\Models\User;

class AnalyzePricingTrendsTool extends AiTool
{
    private const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'analyze_pricing_trends';
    }

    public function description(): string
    {
        return 'Analyze current data plan pricing and markup trends across the catalog, highlighting plans with unusually low or high margins and pricing strategies that may need review.';
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
                'network' => ['type' => 'string', 'description' => 'Filter by network name.'],
                'plan_type' => ['type' => 'string', 'description' => 'Filter by plan type.'],
                'role' => ['type' => 'string', 'description' => 'Customer role to evaluate pricing for, default is user.'],
                'active_only' => ['type' => 'boolean', 'description' => 'Only include active plans. Default true.'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows to return (1-50).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'network' => 'nullable|string|max:40',
            'plan_type' => 'nullable|string|max:40',
            'role' => 'nullable|string|max:60',
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
        if (!empty($arguments['active_only'])) {
            $query->where('active', true);
        }

        $limit = min((int) ($arguments['limit'] ?? 25), self::MAX_LIMIT);
        $role = $arguments['role'] ?? 'user';

        $plans = $query->orderBy('network')->orderBy('id')->limit($limit)->get();

        $rows = $plans->map(fn (DataPlan $plan) => $this->describePlanPricing($plan, $role));

        $markupValues = $rows->pluck('markup_percentage')->filter()->toArray();
        $averageMarkup = $markupValues ? array_sum($markupValues) / count($markupValues) : null;

        return [
            'role' => $role,
            'average_markup_percentage' => $averageMarkup !== null ? round($averageMarkup, 2) : null,
            'note' => 'Markup is calculated relative to provider cost price. A percentage greater than 0 indicates a markup on cost.',
            'plans' => $rows,
        ];
    }

    private function describePlanPricing(DataPlan $plan, string $role): array
    {
        $pricingConfig = $plan->pricing[$role] ?? null;
        $cost = $plan->resolveCostPrice();
        $price = null;
        $markupPercentage = null;
        $pricingType = null;

        if (is_array($pricingConfig)) {
            $pricingType = $pricingConfig['type'] ?? 'fiat';
            $value = (float) ($pricingConfig['value'] ?? 0);
            if ($pricingType === 'percentage') {
                $price = round($cost * (1 + $value / 100), 2);
                $markupPercentage = $value;
            } else {
                $price = $value;
                $markupPercentage = $cost > 0 ? round((($price - $cost) / $cost) * 100, 2) : null;
            }
        } elseif ($pricingConfig !== null) {
            $price = (float) $pricingConfig;
            $pricingType = 'legacy_fiat';
            $markupPercentage = $cost > 0 ? round((($price - $cost) / $cost) * 100, 2) : null;
        } else {
            $price = $plan->price;
            $pricingType = 'fallback';
            $markupPercentage = $cost > 0 ? round((($price - $cost) / $cost) * 100, 2) : null;
        }

        return [
            'plan_id' => $plan->id,
            'network' => $plan->network,
            'plan_name' => $plan->plan_name,
            'plan_size' => $plan->plan_size,
            'plan_type' => $plan->plan_type,
            'active' => (bool) $plan->active,
            'price' => $price,
            'pricing_type' => $pricingType,
            'cost_price' => $cost,
            'markup_percentage' => $markupPercentage,
        ];
    }
}
