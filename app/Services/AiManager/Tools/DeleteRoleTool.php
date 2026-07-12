<?php

namespace App\Services\AiManager\Tools;

use App\Models\Role;
use App\Models\User;
use App\Services\AiManager\AiManagerException;

class DeleteRoleTool extends AiTool
{
    public function name(): string
    {
        return 'delete_role';
    }

    public function description(): string
    {
        return 'Propose deleting an existing role. Creates a pending action that must be approved before the role is removed.';
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
                'role_id' => ['type' => 'integer', 'description' => 'Numeric id of the role to delete.'],
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

    public function summarize(array $arguments): string
    {
        return 'Delete role #' . ($arguments['role_id'] ?? 'unknown');
    }

    public function handle(array $arguments, User $actor): array
    {
        $role = Role::find($arguments['role_id']);
        if (!$role) {
            throw new AiManagerException('Role not found.');
        }

        $role->delete();

        return [
            'deleted' => true,
            'role_id' => $arguments['role_id'],
        ];
    }
}
