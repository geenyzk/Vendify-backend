<?php

namespace App\Services\AiManager\Tools;

use App\Models\Role;
use App\Models\User;
use App\Services\AiManager\AiManagerException;

class GetRoleTool extends AiTool
{
    public function name(): string
    {
        return 'get_role';
    }

    public function description(): string
    {
        return 'Retrieve the details of a specific role, including permissions and upgrade settings.';
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
                'role_id' => ['type' => 'integer', 'description' => 'Numeric id of the role.'],
            ],
            'required' => ['role_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'role_id' => 'required|integer',
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $role = Role::with('permissions', 'serviceCostMargins', 'users')->find($arguments['role_id']);
        if (!$role) {
            throw new AiManagerException('Role not found.');
        }

        return [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_active' => (bool) $role->is_active,
                'is_default' => (bool) $role->is_default,
                'upgradable' => (bool) $role->upgradable,
                'upgrade_cost' => $role->upgrade_cost,
                'permission_slugs' => $role->permissions->pluck('slug')->all(),
                'service_cost_margins' => $role->serviceCostMargins->map(fn ($margin) => [
                    'id' => $margin->id,
                    'service_type' => $margin->service_type,
                    'margin_type' => $margin->margin_type,
                    'margin_value' => $margin->margin_value,
                ])->all(),
                'user_count' => $role->users->count(),
            ],
        ];
    }
}
