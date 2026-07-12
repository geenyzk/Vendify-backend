<?php

namespace App\Services\AiManager\Tools;

use App\Models\DataPlan;
use App\Models\Role;
use App\Models\User;
use App\Services\AiManager\AiManagerException;
use App\Support\PerformanceCache;

/**
 * Set a data plan's selling price for a given customer role, writing the
 * `pricing` JSON entry the DataPlan price accessor reads
 * (role => {type: fiat|percentage, value}). Mutating: proposal-only, gated by
 * `settings`, since prices directly affect revenue.
 */
class UpdateDataPlanPriceTool extends AiTool
{
    public function name(): string
    {
        return 'update_data_plan_price';
    }

    public function description(): string
    {
        return 'Propose changing a data plan\'s selling price for a specific customer role. A "fiat" price is the exact naira amount charged; a "percentage" price is a markup over the provider\'s cost price. Find the plan id and current pricing first with search_data_plans. Creates a pending action an admin must approve.';
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
                'price_type' => ['type' => 'string', 'enum' => ['fiat', 'percentage'], 'description' => 'fiat = exact price; percentage = markup over cost price.'],
                'value' => ['type' => 'number', 'description' => 'The price (fiat) or markup percent (percentage).'],
            ],
            'required' => ['plan_id', 'price_type', 'value'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'plan_id' => 'required|integer',
            'role' => 'nullable|string|max:60',
            'price_type' => 'required|in:fiat,percentage',
            'value' => 'required|numeric|min:0',
        ];
    }

    public function summarize(array $arguments): string
    {
        $role = $arguments['role'] ?? 'user';
        $price = $arguments['price_type'] === 'percentage'
            ? "{$arguments['value']}% markup"
            : 'NGN ' . number_format((float) $arguments['value'], 2);

        return "Set data plan #{$arguments['plan_id']} price for role '{$role}' to {$price}";
    }

    public function handle(array $arguments, User $actor): array
    {
        $plan = DataPlan::find($arguments['plan_id']);
        if (!$plan) {
            throw new AiManagerException('Data plan not found.');
        }

        $role = $arguments['role'] ?? 'user';
        if ($role !== 'user') {
            $role = $this->resolveRoleName($role);
            if (!$role) {
                throw new AiManagerException("No such customer role '{$arguments['role']}'.");
            }
        }

        $pricing = is_array($plan->pricing) ? $plan->pricing : [];
        $previous = $pricing[$role] ?? null;

        $pricing[$role] = [
            'type' => $arguments['price_type'],
            'value' => (float) $arguments['value'],
        ];

        $plan->pricing = $pricing;
        $plan->save();
        PerformanceCache::clearCatalog();

        return [
            'updated' => true,
            'plan_id' => $plan->id,
            'role' => $role,
            'previous' => $previous,
            'new' => $pricing[$role],
        ];
    }

    private function resolveRoleName(string $role): ?string
    {
        $roleModel = Role::where('slug', $role)->orWhere('name', $role)->first();
        return $roleModel?->name;
    }
}
