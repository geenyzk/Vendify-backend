<?php

namespace App\Services\AiManager\Tools;

use App\Models\DataPlan;
use App\Models\Role;
use App\Models\User;

class RecommendPricingStrategyTool extends AiTool
{
    private const MAX_RECOMMENDATIONS = 10;

    public function name(): string
    {
        return 'recommend_pricing_strategy';
    }

    public function description(): string
    {
        return 'Provide pricing strategy recommendations for data plans based on current markup, cost coverage, and customer role pricing. Suggest which plans should be reviewed, which pricing model to use, and whether markups may be too low or too high.';
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
                'role' => ['type' => 'string', 'description' => 'Customer role to evaluate, default is user.'],
                'network' => ['type' => 'string', 'description' => 'Optional network filter, e.g. mtn.'],
                'plan_type' => ['type' => 'string', 'description' => 'Optional plan type filter.'],
                'limit' => ['type' => 'integer', 'description' => 'Max number of sample plans to analyze (1-50).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'role' => 'nullable|string|max:60',
            'network' => 'nullable|string|max:40',
            'plan_type' => 'nullable|string|max:40',
            'limit' => 'nullable|integer|min:1|max:50',
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $role = $arguments['role'] ?? 'user';
        if ($role !== 'user') {
            $normalized = $this->resolveRoleName($role);
            if (!$normalized) {
                return [
                    'role' => $role,
                    'recommendations' => [],
                    'note' => "Role '{$role}' does not exist; use a valid customer role name or slug.",
                ];
            }
            $role = $normalized;
        }

        $query = DataPlan::query();
        if (!empty($arguments['network'])) {
            $query->where('network', strtolower($arguments['network']));
        }
        if (!empty($arguments['plan_type'])) {
            $query->where('plan_type', $arguments['plan_type']);
        }

        $plans = $query->where('active', true)
            ->orderBy('network')
            ->orderBy('id')
            ->limit((int) ($arguments['limit'] ?? 30))
            ->get();

        $analysis = $plans->map(fn (DataPlan $plan) => $this->analyzePlan($plan, $role))->filter();
        $recommendations = $this->buildRecommendations($analysis->toArray());

        return [
            'role' => $role,
            'sampled_plans' => $analysis,
            'recommendations' => array_slice($recommendations, 0, self::MAX_RECOMMENDATIONS),
        ];
    }

    private function resolveRoleName(string $role): ?string
    {
        $roleModel = Role::where('slug', $role)->orWhere('name', $role)->first();
        return $roleModel?->name;
    }

    private function analyzePlan(DataPlan $plan, string $role): ?array
    {
        $pricingConfig = $plan->pricing[$role] ?? null;
        $cost = $plan->resolveCostPrice();
        if ($cost <= 0) {
            return null;
        }

        $price = null;
        $pricingType = null;
        $markupPercent = null;

        if (is_array($pricingConfig)) {
            $pricingType = $pricingConfig['type'] ?? 'fiat';
            $value = (float) ($pricingConfig['value'] ?? 0);
            if ($pricingType === 'percentage') {
                $price = round($cost * (1 + $value / 100), 2);
                $markupPercent = $value;
            } else {
                $price = $value;
                $markupPercent = round((($price - $cost) / $cost) * 100, 2);
            }
        } elseif ($pricingConfig !== null) {
            $price = (float) $pricingConfig;
            $pricingType = 'legacy_fiat';
            $markupPercent = round((($price - $cost) / $cost) * 100, 2);
        } else {
            $price = $plan->price;
            $pricingType = 'fallback';
            $markupPercent = $cost > 0 ? round((($price - $cost) / $cost) * 100, 2) : null;
        }

        if ($price === null) {
            return null;
        }

        return [
            'plan_id' => $plan->id,
            'network' => $plan->network,
            'plan_name' => $plan->plan_name,
            'plan_size' => $plan->plan_size,
            'plan_type' => $plan->plan_type,
            'pricing_type' => $pricingType,
            'cost_price' => $cost,
            'price' => $price,
            'markup_percentage' => $markupPercent,
        ];
    }

    private function buildRecommendations(array $analysis): array
    {
        if (empty($analysis)) {
            return ['No active plans with valid cost pricing were found for analysis.'];
        }

        $recs = [];
        $markupPercentages = array_column($analysis, 'markup_percentage');
        $averageMarkup = array_sum($markupPercentages) / count($markupPercentages);

        $tooLow = array_filter($analysis, fn ($plan) => $plan['markup_percentage'] !== null && $plan['markup_percentage'] < max(5, $averageMarkup * 0.5));
        $tooHigh = array_filter($analysis, fn ($plan) => $plan['markup_percentage'] !== null && $plan['markup_percentage'] > max(30, $averageMarkup * 1.5));

        $recs[] = 'Target a consistent markup band around the current average markup of ' . round($averageMarkup, 2) . '%. Plans below that band may be underpriced for your cost basis.';
        $recs[] = 'For plans with markup under 5%, review whether the market demand justifies the low margin or whether a small increase can improve profitability.';
        $recs[] = 'Plans with markup above 30% should be reviewed for pricing sensitivity; consider switching volatile-cost plans to percentage-based pricing so the customer price tracks provider cost changes.';

        if ($tooLow) {
            $sample = array_slice($tooLow, 0, 3);
            $ids = implode(', ', array_map(fn ($plan) => "#{$plan['plan_id']} ({$plan['network']} {$plan['plan_name']}{$plan['plan_size']})", $sample));
            $recs[] = "Underpriced plans sample: {$ids}. Consider raising these toward the average markup to protect margin.";
        }
        if ($tooHigh) {
            $sample = array_slice($tooHigh, 0, 3);
            $ids = implode(', ', array_map(fn ($plan) => "#{$plan['plan_id']} ({$plan['network']} {$plan['plan_name']}{$plan['plan_size']})", $sample));
            $recs[] = "High-margin plans sample: {$ids}. These may tolerate demand-sensitive reductions or should remain premium offerings with clear value messaging.";
        }

        $recs[] = 'Use exact fiat pricing for stable bundles where customers need a definite price, and percentage pricing for plans whose provider cost can vary or is tied to wholesale market changes.';
        $recs[] = 'If your platform supports tiered customer roles, keep role-specific pricing aligned to the same margin strategy rather than arbitrary fixed gaps.';

        return $recs;
    }
}
