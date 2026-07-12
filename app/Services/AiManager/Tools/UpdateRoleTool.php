<?php

namespace App\Services\AiManager\Tools;

use App\Models\Role;
use App\Models\User;
use App\Services\AiManager\AiManagerException;

class UpdateRoleTool extends AiTool
{
    public function name(): string
    {
        return 'update_role';
    }

    public function description(): string
    {
        return 'Propose updating an existing role, including its active state, default status, upgrade settings, and permissions. Creates a pending action that must be approved before applying the change.';
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
                'role_id' => ['type' => 'integer', 'description' => 'Numeric id of the role to update.'],
                'name' => ['type' => 'string', 'description' => 'New display name for the role.'],
                'slug' => ['type' => 'string', 'description' => 'New machine slug for the role.'],
                'description' => ['type' => 'string', 'description' => 'Optional description.'],
                'is_active' => ['type' => 'boolean', 'description' => 'Whether the role is active.'],
                'is_default' => ['type' => 'boolean', 'description' => 'Whether this should be the default role. If true, other roles lose default status.'],
                'upgradable' => ['type' => 'boolean', 'description' => 'Whether users can upgrade into this role.'],
                'upgrade_cost' => ['type' => 'number', 'description' => 'Cost to upgrade into this role, required when upgradable is true.'],
                'permission_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'List of permission ids to assign to the role.',
                ],
            ],
            'required' => ['role_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'role_id' => 'required|integer',
            'name' => 'nullable|string|unique:roles,name,' . ($this->roleIdFromArguments() ?? 'NULL'),
            'slug' => 'nullable|string|unique:roles,slug,' . ($this->roleIdFromArguments() ?? 'NULL'),
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'upgradable' => 'nullable|boolean',
            'upgrade_cost' => 'nullable|numeric|min:0|required_if:upgradable,true',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ];
    }

    public function summarize(array $arguments): string
    {
        return 'Update role #' . ($arguments['role_id'] ?? 'unknown');
    }

    public function handle(array $arguments, User $actor): array
    {
        $role = Role::find($arguments['role_id']);
        if (!$role) {
            throw new AiManagerException('Role not found.');
        }

        $data = collect($arguments)->only([
            'name',
            'slug',
            'description',
            'is_active',
            'is_default',
            'upgradable',
            'upgrade_cost',
        ])->filter()->all();

        $role->update($data);

        if (array_key_exists('permission_ids', $arguments)) {
            $role->permissions()->sync($arguments['permission_ids'] ?? []);
        }

        if (array_key_exists('is_default', $arguments) && !empty($arguments['is_default'])) {
            Role::where('id', '!=', $role->id)->update(['is_default' => false]);
        }

        $role->load('permissions');

        return [
            'updated' => true,
            'role_id' => $role->id,
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'permission_slugs' => $role->permissions->pluck('slug')->all(),
            ],
        ];
    }

    private function roleIdFromArguments(): ?int
    {
        return null;
    }
}
