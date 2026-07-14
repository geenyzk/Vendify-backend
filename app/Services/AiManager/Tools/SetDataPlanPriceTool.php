<?php

namespace App\Services\AiManager\Tools;

use App\Models\DataPlan;
use App\Models\Role;
use App\Models\User;
use App\Services\AiManager\AiManagerException;
use App\Support\PerformanceCache;

/**
 * Set or adjust a data plan's selling price for one customer role, writing the
 * `pricing` JSON entry the DataPlan price accessor reads
 * (role => {type: fiat|percentage, value}).
 *
 * Consolidates what used to be two overlapping tools (update_data_plan_price +
 * adjust_data_plan_price) into one, via `mode`:
 *   - fiat                 : exact naira price, stored as a fixed price.
 *   - percentage           : markup % over provider cost, stored so the price
 *                            tracks cost changes going forward.
 *   - increase_by_percentage / decrease_by_percentage : move the CURRENT price
 *                            up/down by a percentage, stored as a fixed price.
 *
 * Mutating: proposal-only, gated by `settings`, since prices affect revenue.
 */
class SetDataPlanPriceTool extends AiTool
{
    private const MODES = ['fiat', 'percentage', 'increase_by_percentage', 'decrease_by_percentage'];

    public function name(): string
    {
        return 'set_data_plan_price';
    }

    public function description(): string
    {
        return 'Propose setting or adjusting a data plan\'s selling price for a customer role. Modes: "fiat" (exact naira price), "percentage" (markup % over provider cost that keeps tracking cost), "increase_by_percentage"/"decrease_by_percentage" (move the current price up/down by a percentage). Find the plan id and current pricing first with search_plans (table data_plans). Creates a pending action an admin must approve.';
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
                'role' => ['type' => 'string', 'description' => 'Customer role name or slug the price applies to (e.g. "user", "reseller"). Default "user".'],
                'mode' => [
                    'type' => 'string',
                    'enum' => self::MODES,
                    'description' => 'fiat = exact price; percentage = markup over cost (tracks cost); increase_by_percentage/decrease_by_percentage = move the current price by a percentage.',
                ],
                'value' => ['type' => 'number', 'description' => 'The naira price (fiat) or the percentage (all other modes).'],
                'reason' => ['type' => 'string', 'description' => 'Optional business rationale shown on the approval card.'],
            ],
            'required' => ['plan_id', 'mode', 'value'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'plan_id' => 'required|integer',
            'role' => 'nullable|string|max:60',
            'mode' => 'required|in:' . implode(',', self::MODES),
            'value' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:1000',
        ];
    }

    public function summarize(array $arguments): string
    {
        $role = $arguments['role'] ?? 'user';
        $value = (float) $arguments['value'];
        $plan = "#{$arguments['plan_id']}";

        return match ($arguments['mode']) {
            'fiat' => "Set data plan {$plan} price for role '{$role}' to NGN " . number_format($value, 2),
            'percentage' => "Set data plan {$plan} price for role '{$role}' to a {$value}% markup over cost",
            'increase_by_percentage' => "Increase data plan {$plan} price for role '{$role}' by {$value}%",
            'decrease_by_percentage' => "Decrease data plan {$plan} price for role '{$role}' by {$value}%",
            default => "Adjust data plan {$plan} price for role '{$role}'",
        };
    }

    public function handle(array $arguments, User $actor): array
    {
        $plan = DataPlan::find($arguments['plan_id']);
        if (!$plan) {
            throw new AiManagerException('Data plan not found.');
        }

        $role = $arguments['role'] ?? 'user';
        if ($role !== 'user') {
            $resolved = $this->resolveRoleName($role);
            if (!$resolved) {
                throw new AiManagerException("No such customer role '{$role}'.");
            }
            $role = $resolved;
        }

        $mode = $arguments['mode'];
        $value = (float) $arguments['value'];
        $cost = $plan->resolveCostPrice();

        $pricing = is_array($plan->pricing) ? $plan->pricing : [];
        $previous = $pricing[$role] ?? null;

        // Modes that operate on cost need a real cost basis.
        if (in_array($mode, ['percentage', 'increase_by_percentage', 'decrease_by_percentage'], true) && $cost <= 0) {
            throw new AiManagerException('Unable to resolve this plan\'s cost price, which the chosen mode needs.');
        }

        if ($mode === 'fiat') {
            $newConfig = ['type' => 'fiat', 'value' => round($value, 2)];
        } elseif ($mode === 'percentage') {
            $newConfig = ['type' => 'percentage', 'value' => round($value, 2)];
        } else {
            $current = $this->currentPriceForRole($plan, $previous, $cost);
            $factor = $mode === 'increase_by_percentage' ? (1 + $value / 100) : max(0, 1 - $value / 100);
            $newConfig = ['type' => 'fiat', 'value' => round($current * $factor, 2)];
        }

        $pricing[$role] = $newConfig;
        $plan->pricing = $pricing;
        $plan->save();
        PerformanceCache::clearCatalog();

        return [
            'updated' => true,
            'plan_id' => $plan->id,
            'role' => $role,
            'mode' => $mode,
            'previous' => $previous,
            'new' => $newConfig,
            'effective_price' => $this->currentPriceForRole($plan, $newConfig, $cost),
            'cost_price' => $cost,
            'reason' => $arguments['reason'] ?? null,
        ];
    }

    /** The naira price a role pays given a pricing config (fiat, percentage, or fallback). */
    private function currentPriceForRole(DataPlan $plan, mixed $pricingConfig, float $cost): float
    {
        if (is_array($pricingConfig)) {
            if (($pricingConfig['type'] ?? 'fiat') === 'percentage') {
                return round($cost * (1 + (float) ($pricingConfig['value'] ?? 0) / 100), 2);
            }
            return round((float) ($pricingConfig['value'] ?? 0), 2);
        }

        if ($pricingConfig !== null) {
            return round((float) $pricingConfig, 2);
        }

        return round((float) $plan->price, 2);
    }

    private function resolveRoleName(string $role): ?string
    {
        return Role::where('slug', $role)->orWhere('name', $role)->first()?->name;
    }
}
