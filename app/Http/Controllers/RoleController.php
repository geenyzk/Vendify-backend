<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Permission;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    /**
     * Get all roles.
     */
    public function index(): JsonResponse
    {
        // Role selectors are used across most admin forms. Returning every
        // full User model for every role made those lightweight lookups grow
        // with the entire customer table. The UI only needs the assignment
        // count; the dedicated /roles/{id}/users endpoint serves actual users.
        $roles = Role::query()
            ->with(['serviceCostMargins', 'permissions'])
            ->withCount('users')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $roles->toResourceCollection(),
        ]);
    }

    /**
     * Get a specific role with its cost margins.
     */
    public function show($id): JsonResponse
    {
        $role = Role::query()
            ->with(['serviceCostMargins', 'permissions'])
            ->withCount('users')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $role->toResource(),
        ]);
    }

    /**
     * Create a new role.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:roles',
            'slug' => 'required|string|unique:roles',
            'description' => 'nullable|string',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'upgradable' => 'boolean',
            'upgrade_cost' => 'nullable|numeric|min:0|required_if:upgradable,true',
            'permission_ids' => 'array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        if (in_array(strtolower($validated['slug']), Role::PROTECTED_SLUGS, true) && !$request->user()->hasPermission('manage_system_roles')) {
            return $this->forbidden('Only system-role managers can create a protected role.');
        }
        if ($this->containsProtectedPermission($validated['permission_ids'] ?? []) && !$request->user()->hasPermission('manage_system_roles')) {
            return $this->forbidden('You cannot grant protected system permissions.');
        }

        $role = DB::transaction(function () use ($validated) {
            $role = Role::create(collect($validated)->except('permission_ids')->all());
            if (!empty($validated['is_default'])) Role::where('id', '!=', $role->id)->update(['is_default' => false]);
            if (array_key_exists('permission_ids', $validated)) $role->permissions()->sync($validated['permission_ids']);
            return $role;
        });
        AuditLogger::record('role_created', subject: $role, description: "Role {$role->name} was created.");

        $role->load('permissions');

        return response()->json([
            'success' => true,
            'data' => $role->toResource(),
            'message' => 'Role created successfully',
        ], 201);
    }

    /**
     * Update a role.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if ($role->isProtected() && !$request->user()->hasPermission('manage_system_roles')) {
            return $this->forbidden('Only system-role managers can modify this protected role.');
        }

        $validated = $request->validate([
            'name' => 'string|unique:roles,name,'.$id,
            'slug' => 'string|unique:roles,slug,'.$id,
            'description' => 'nullable|string',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'upgradable' => 'boolean',
            'upgrade_cost' => 'nullable|numeric|min:0|required_if:upgradable,true',
            'permission_ids' => 'array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $nextSlug = strtolower((string) ($validated['slug'] ?? $role->slug));
        if (in_array($nextSlug, Role::PROTECTED_SLUGS, true) && !$request->user()->hasPermission('manage_system_roles')) {
            return $this->forbidden('Only system-role managers can use a protected role identifier.');
        }
        if (array_key_exists('permission_ids', $validated) && !$request->user()->hasPermission('manage_system_roles')) {
            $currentProtected = $role->permissions()->whereIn('slug', Role::PROTECTED_PERMISSION_SLUGS)->pluck('permissions.id')->sort()->values()->all();
            $nextProtected = $this->protectedPermissionIds($validated['permission_ids']);
            if ($currentProtected !== $nextProtected) {
                return $this->forbidden('You cannot add or remove protected system permissions.');
            }
        }
        if ($role->slug === 'owner') {
            if (array_key_exists('is_active', $validated) && !$validated['is_active']) {
                return response()->json(['success' => false, 'message' => 'The owner role cannot be deactivated.'], 422);
            }
            if (array_key_exists('permission_ids', $validated) && !$this->permissionIdsContain('manage_system_roles', $validated['permission_ids'])) {
                return response()->json(['success' => false, 'message' => 'The owner role must retain manage_system_roles.'], 422);
            }
        }

        $before = $role->only(['name', 'slug', 'is_active', 'is_default']);
        DB::transaction(function () use ($role, $validated) {
            $role->update(collect($validated)->except('permission_ids')->all());
            if (array_key_exists('permission_ids', $validated)) $role->permissions()->sync($validated['permission_ids']);
            if (!empty($validated['is_default'])) Role::where('id', '!=', $role->id)->update(['is_default' => false]);
        });
        AuditLogger::record('role_updated', subject: $role, changes: ['before' => $before, 'after' => $role->fresh()->only(['name', 'slug', 'is_active', 'is_default'])]);

        $role->load('permissions');

        return response()->json([
            'success' => true,
            'data' => $role->toResource(),
            'message' => 'Role updated successfully',
        ]);
    }

    /**
     * Delete a role.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $role = Role::findOrFail($id);
        if ($role->isProtected()) {
            return response()->json(['success' => false, 'message' => 'Protected system roles cannot be deleted.'], 422);
        }
        if ($role->users()->exists()) {
            return response()->json(['success' => false, 'message' => 'Reassign users before deleting this role.'], 422);
        }
        $label = $role->name;
        $role->delete();
        AuditLogger::record('role_deleted', description: "Role {$label} was deleted.");

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully',
        ]);
    }

    /**
     * Get all users with a specific role.
     */
    public function users($id): JsonResponse
    {
        $role = Role::findOrFail($id);
        $users = $role->users()->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    private function containsProtectedPermission(array $ids): bool
    {
        return Permission::whereIn('id', $ids)->whereIn('slug', Role::PROTECTED_PERMISSION_SLUGS)->exists();
    }

    private function protectedPermissionIds(array $ids): array
    {
        return Permission::whereIn('id', $ids)->whereIn('slug', Role::PROTECTED_PERMISSION_SLUGS)->pluck('id')->sort()->values()->all();
    }

    private function permissionIdsContain(string $slug, array $ids): bool
    {
        return Permission::whereIn('id', $ids)->where('slug', $slug)->exists();
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
