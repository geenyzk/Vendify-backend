<?php

namespace App\Services\AiManager\Tools;

use App\Models\Role;
use App\Models\User;

class ListRolesTool extends AiTool
{
    private const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'list_roles';
    }

    public function description(): string
    {
        return 'List customer and staff roles, including permissions and configuration flags. Use this to inspect the current role setup before proposing changes.';
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
                'query' => ['type' => 'string', 'description' => 'Optional search term to match against role name or slug.'],
                'active_only' => ['type' => 'boolean', 'description' => 'Only include active roles. Default false.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of roles to return (1-50).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'query' => 'nullable|string|max:120',
            'active_only' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $query = Role::query();

        if (!empty($arguments['query'])) {
            $term = $arguments['query'];
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%");
            });
        }

        if (!empty($arguments['active_only'])) {
            $query->where('is_active', true);
        }

        $limit = min((int) ($arguments['limit'] ?? 25), self::MAX_LIMIT);
        $roles = $query->orderBy('is_staff', 'desc')->orderBy('name')->limit($limit)->get();

        return [
            'returned' => $roles->count(),
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_active' => (bool) $role->is_active,
                'is_default' => (bool) $role->is_default,
                'upgradable' => (bool) $role->upgradable,
                'upgrade_cost' => $role->upgrade_cost,
                'permission_slugs' => $role->permissions()->pluck('slug')->all(),
            ]),
        ];
    }
}
