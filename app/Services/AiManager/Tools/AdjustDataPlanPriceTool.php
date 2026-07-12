<?php

namespace App\Services\AiManager\Tools;

use App\Models\DataPlan;
use App\Models\Role;
use App\Models\User;
use App\Services\AiManager\AiManagerException;

class AdjustDataPlanPriceTool extends AiTool
{
    public function name(): string
    {
        return 'adjust_data_plan_price';
    }

    public function description(): string
    {
        return 'Propose adjusting a data plan price up or down based on a business pricing strategy, such as raising the price by a percentage or targeting a desired markup over cost. This creates a pending proposal for admin approval.';
    }

    public function isMutating(): bool
    {
        return true;
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
                'plan_id' => ['type' => 'integer', 'description' => 'Numeric id of the data plan.'],
                'role' => ['type' => 'string', 'description' => 'Customer role to adjust pricing for (default: user).'],
                'adjustment_type' => [
                    'type' => 'string',
                    'enum' => ['increase_by_percentage', 'decrease_by_percentage', 'target_markup'],
                    'description' => 'Business strategy for the adjustment: increase or decrease by a percentage, or set a target markup over cost.',
                ],
                'value' => ['type' => 'number', 'description' => 'The percentage value for the adjustment or target markup.'],
                'reason' => ['type' => 'string', 'description' => 'Optional business rationale for the proposed adjustment.'],
            ],
            'required' => ['plan_id', 'adjustment_type', 'value'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'plan_id' => 'required|integer',
            'role' => 'nullable|string|max:60',
            'adjustment_type' => 'required|in:increase_by_percentage,decrease_by_percentage,target_markup',
            'value' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:1000',
        ];
    }

    public function summarize(array $arguments): string
    {
        $role = $arguments['role'] ?? 'user';
        $value = number_format((float) $arguments['value'], 2);

        return match ($arguments['adjustment_type']) {
            'increase_by_percentage' => "Increase plan #{$arguments['plan_id']} price for role {$role} by {$value}%.",
            'decrease_by_percentage' => "Decrease plan #{$arguments['plan_id']} price for role {$role} by {$value}%.",
            'target_markup' => "Set plan #{$arguments['plan_id']} price for role {$role} to target a {$value}% markup over cost.",
            default => "Adjust price for plan #{$arguments['plan_id']} for role {$role}.",
        };
    }

    public function handle(array $arguments, User $actor): array
    {
        $plan = DataPlan::find($arguments['plan_id']);
        if (!$plan) {
            throw new AiManagerException('Data plan not found.');
        }

        $role = $arguments['role'] ?? 'user';
        if ($role !== 'user' && !Role::where('slug', $role)->orWhere('name', $role)->exists()) {
            throw new AiManagerException("No such customer role '{$role}'.");
        }

        $currentPricing = $plan->pricing[$role] ?? null;
        $currentPrice = $this->currentPriceForRole($plan, $role, $currentPricing);
        $cost = $plan->resolveCostPrice();

        if ($cost <= 0) {
            throw new AiManagerException('Unable to calculate cost price for this plan.');
        }

        $adjustmentType = $arguments['adjustment_type'];
        $value = (float) $arguments['value'];
        $newPrice = $this->calculateNewPrice($currentPrice, $cost, $adjustmentType, $value);

        $pricing = is_array($plan->pricing) ? $plan->pricing : [];
        $pricing[$role] = [
            'type' => 'fiat',
            'value' => round($newPrice, 2),
        ];

        $plan->pricing = $pricing;
        $plan->save();

        return [
            'updated' => true,
            'plan_id' => $plan->id,
            'role' => $role,
            'current_price' => $currentPrice,
            'new_price' => round($newPrice, 2),
            'cost_price' => $cost,
            'strategy' => $adjustmentType,
            'reason' => $arguments['reason'] ?? null,
        ];
    }

    private function currentPriceForRole(DataPlan $plan, string $role, $pricingConfig): float
    {
        if (is_array($pricingConfig)) {
            if (($pricingConfig['type'] ?? 'fiat') === 'percentage') {
                $cost = $plan->resolveCostPrice();
                return round($cost * (1 + (float) ($pricingConfig['value'] ?? 0) / 100), 2);
            }
            return (float) $pricingConfig['value'];
        }

        if ($pricingConfig !== null) {
            return (float) $pricingConfig;
        }

        return (float) $plan->price;
    }

    private function calculateNewPrice(float $currentPrice, float $cost, string $type, float $value): float
    {
        return match ($type) {
            'increase_by_percentage' => round($currentPrice * (1 + $value / 100), 2),
            'decrease_by_percentage' => round($currentPrice * max(0, 1 - $value / 100), 2),
            'target_markup' => round($cost * (1 + $value / 100), 2),
            default => $currentPrice,
        };
    }
}
