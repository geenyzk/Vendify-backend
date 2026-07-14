<?php

namespace App\Services\AiManager\Tools;

use App\Http\Controllers\ServiceCostMarginController;
use App\Models\Role;
use App\Models\User;
use App\Services\AiManager\AiManagerException;
use App\Services\AiManager\Tools\Concerns\CallsControllerAction;
use Illuminate\Http\Request;

/**
 * Set the cost margins (cost price + profit margin) for one or more service
 * types on a customer role. Mutating: proposal-only, gated by `customers`.
 * Delegates to ServiceCostMarginController::bulkUpdateByRole (updateOrCreate).
 */
class SetRoleCostMarginsTool extends AiTool
{
    use CallsControllerAction;

    public function name(): string
    {
        return 'set_role_cost_margins';
    }

    public function description(): string
    {
        return 'Set service cost margins for a customer role: for each service type, its cost price and the profit margin (margin_type fiat = flat naira, percentage = percent of cost). Existing entries for the same service type are overwritten. Review current values with get_role_cost_margins first. Creates a pending action an admin must approve.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'customers';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'role' => ['type' => 'string', 'description' => 'Customer role name, slug, or numeric id.'],
                'margins' => [
                    'type' => 'array',
                    'description' => 'The margins to set.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'service_type' => ['type' => 'string', 'description' => 'e.g. airtime_recharge, data_subscription, cable_subscription, electric_bill.'],
                            'cost_price' => ['type' => 'number', 'description' => 'Cost basis in naira.'],
                            'margin_profit' => ['type' => 'number', 'description' => 'Profit margin (flat naira or percent, per margin_type).'],
                            'margin_type' => ['type' => 'string', 'enum' => ['fiat', 'percentage'], 'description' => 'Default fiat.'],
                        ],
                        'required' => ['service_type', 'cost_price', 'margin_profit'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['role', 'margins'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'role' => 'required|string|max:60',
            'margins' => 'required|array|min:1',
            'margins.*.service_type' => 'required|string|max:60',
            'margins.*.cost_price' => 'required|numeric|min:0',
            'margins.*.margin_profit' => 'required|numeric|min:0',
            'margins.*.margin_type' => 'nullable|in:fiat,percentage',
        ];
    }

    public function summarize(array $arguments): string
    {
        $count = count($arguments['margins'] ?? []);

        return "Set {$count} service cost margin(s) for role '{$arguments['role']}'";
    }

    public function handle(array $arguments, User $actor): array
    {
        $role = $this->resolveRole($arguments['role']);
        if (!$role) {
            throw new AiManagerException("No such customer role '{$arguments['role']}'.");
        }

        $request = Request::create('/', 'POST', ['margins' => $arguments['margins']]);

        $data = $this->unwrap(
            app(ServiceCostMarginController::class)->bulkUpdateByRole($request, $role->id),
            'The cost margins could not be updated.',
        );

        return [
            'updated' => true,
            'role' => $role->name,
            'role_id' => $role->id,
            'margins' => $data,
        ];
    }

    private function resolveRole(string $role): ?Role
    {
        if (ctype_digit($role)) {
            return Role::find((int) $role);
        }

        return Role::where('slug', $role)->orWhere('name', $role)->first();
    }
}
