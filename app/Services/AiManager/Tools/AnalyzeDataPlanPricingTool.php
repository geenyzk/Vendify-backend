<?php

namespace App\Services\AiManager\Tools;

use App\Models\DataPlan;
use App\Models\Role;
use App\Models\User;

/**
 * One read-only pricing lens over the data-plan catalog. Returns each plan's
 * pricing type, cost, price and markup for a role, plus the pricing-type mix
 * and the average markup, so the assistant can reason about margins and make
 * its own recommendations.
 *
 * Consolidates three near-identical tools (get_pricing_strategy,
 * recommend_pricing_strategy, analyze_pricing_trends) that all queried the same
 * rows and computed the same markup — the model turns this data into strategy.
 */
class AnalyzeDataPlanPricingTool extends AiTool
{
    private const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'analyze_data_plan_pricing';
    }

    public function description(): string
    {
        return 'Review data plan pricing and margins for a customer role: per-plan cost, price, pricing type and markup %, plus the average markup and the fiat/percentage pricing mix. Use this to spot under- or over-priced plans and to recommend pricing changes (then propose them with set_data_plan_price).';
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
                'role' => ['type' => 'string', 'description' => 'Customer role to evaluate pricing for. Default "user".'],
                'network' => ['type' => 'string', 'description' => 'Optional network filter, e.g. mtn.'],
                'plan_type' => ['type' => 'string', 'description' => 'Optional plan type filter, e.g. SME.'],
                'active_only' => ['type' => 'boolean', 'description' => 'Only include active plans. Default true.'],
                'limit' => ['type' => 'integer', 'description' => 'Max plans to analyze (1-50, default 25).'],
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
        $role = $arguments['role'] ?? 'user';
        if ($role !== 'user') {
            $resolved = $this->resolveRoleName($role);
            if (!$resolved) {
                return [
                    'role' => $role,
                    'plan_count' => 0,
                    'note' => "Role '{$role}' does not exist; use a valid customer role name or slug.",
                    'plans' => [],
                ];
            }
            $role = $resolved;
        }

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
        // Default active_only to true unless explicitly set false.
        if (($arguments['active_only'] ?? true)) {
            $query->where('active', true);
        }

        $limit = min((int) ($arguments['limit'] ?? 25), self::MAX_LIMIT);
        $plans = $query->orderBy('network')->orderBy('id')->limit($limit)->get();

        $rows = [];
        $mix = ['fiat' => 0, 'percentage' => 0, 'fallback' => 0];
        $markups = [];

        foreach ($plans as $plan) {
            $row = $this->describePlanPricing($plan, $role);
            $mix[$row['pricing_type']] = ($mix[$row['pricing_type']] ?? 0) + 1;
            if ($row['markup_percentage'] !== null) {
                $markups[] = $row['markup_percentage'];
            }
            $rows[] = $row;
        }

        $averageMarkup = $markups ? round(array_sum($markups) / count($markups), 2) : null;

        return [
            'role' => $role,
            'plan_count' => count($rows),
            'average_markup_percentage' => $averageMarkup,
            'pricing_mix' => $mix,
            'note' => 'Markup is relative to editable cost price. "fiat" = fixed naira markup, "percentage" = percentage markup, "fallback" = no role-specific pricing entry.',
            'plans' => $rows,
        ];
    }

    private function describePlanPricing(DataPlan $plan, string $role): array
    {
        $pricingConfig = $plan->pricing[$role] ?? null;
        $cost = $plan->resolveCostPrice();
        $pricingType = 'fallback';
        $price = (float) $plan->price;

        if (is_array($pricingConfig)) {
            $pricingType = ($pricingConfig['type'] ?? 'fiat') === 'percentage' ? 'percentage' : 'fiat';
            $value = (float) ($pricingConfig['value'] ?? 0);
            $price = $pricingType === 'percentage'
                ? round($cost * (1 + $value / 100), 2)
                : round($cost + $value, 2);
        } elseif ($pricingConfig !== null) {
            $pricingType = 'fiat';
            $price = round((float) $pricingConfig, 2);
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
        return Role::where('slug', $role)->orWhere('name', $role)->first()?->name;
    }
}
