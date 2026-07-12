<?php

namespace App\Services\AiManager\Tools;

use App\Models\Role;
use App\Models\User;

class CreateRoleTool extends AiTool
{
    public function name(): string
    {
        return 'create_role';
    }

    public function description(): string
    {
        return 'Propose creating a new customer or staff role, including its permissions and upgrade settings. Creates a pending action that must be approved before applying the change.';
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
                'name' => ['type' => 'string', 'description' => 'Display name of the role.'],
                'slug' => ['type' => 'string', 'description' => 'Machine slug of the role.'],
                'description' => ['type' => 'string', 'description' => 'Optional description.'],
                'is_active' => ['type' => 'boolean', 'description' => 'Whether the role is active. Default true.'],
                'is_default' => ['type' => 'boolean', 'description' => 'Whether this should be the default role. If true, other roles lose default status.'],
                'upgradable' => ['type' => 'boolean', 'description' => 'Whether users can upgrade into this role.'],
                'upgrade_cost' => ['type' => 'number', 'description' => 'Cost to upgrade into this role, required when upgradable is true.'],
                'permission_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'List of permission ids to assign to the role.',
                ],
            ],
            'required' => ['name', 'slug'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|unique:roles',
            'slug' => 'required|string|unique:roles',
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
        return 'Create role ' . ($arguments['name'] ?? 'new role');
    }

    public function handle(array $arguments, User $actor): array
    {
        $data = collect($arguments)->only([
            'name',
            'slug',
            'description',
            'is_active',
            'is_default',
            'upgradable',
            'upgrade_cost',
        ])->filter(fn ($value) => $value !== null)->all();

        $role = Role::create($data);

        if (!empty($arguments['is_default'])) {
            Role::where('id', '!=', $role->id)->update(['is_default' => false]);
        }

        if (!empty($arguments['permission_ids'])) {
            $role->permissions()->sync($arguments['permission_ids']);
        }

        $role->load('permissions');

        return [
            'created' => true,
            'role_id' => $role->id,
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'permission_slugs' => $role->permissions->pluck('slug')->all(),
            ],
        ];
    }
}
