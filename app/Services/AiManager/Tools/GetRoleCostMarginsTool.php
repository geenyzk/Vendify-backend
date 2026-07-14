<?php

namespace App\Services\AiManager\Tools;

use App\Models\Role;
use App\Models\User;

/**
 * Per-role service cost margins — the cost basis and profit margin configured
 * for each service type for a given customer role. Grounds pricing/margin
 * discussions and precedes a set_role_cost_margins proposal.
 */
class GetRoleCostMarginsTool extends AiTool
{
    public function name(): string
    {
        return 'get_role_cost_margins';
    }

    public function description(): string
    {
        return 'Get the service cost margins configured for a customer role: for each service type, the cost price, the margin profit, and the margin type (fiat or percentage). Use before proposing changes with set_role_cost_margins.';
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
            ],
            'required' => ['role'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'role' => 'required|string|max:60',
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $role = $this->resolveRole($arguments['role']);
        if (!$role) {
            return ['role' => $arguments['role'], 'note' => 'No such role.', 'margins' => []];
        }

        $margins = $role->serviceCostMargins()->get()->map(fn ($m) => [
            'service_type' => $m->service_type,
            'cost_price' => (float) $m->cost_price,
            'margin_profit' => (float) $m->margin_profit,
            'margin_type' => $m->margin_type,
            'is_active' => (bool) ($m->is_active ?? true),
        ]);

        return [
            'role' => $role->name,
            'role_id' => $role->id,
            'margins' => $margins,
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
