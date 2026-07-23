<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $role = Role::create(collect($validated)->except('permission_ids')->all());

        // If this role is set as default, unset default on others.
        if (! empty($validated['is_default'])) {
            Role::where('id', '!=', $role->id)->update(['is_default' => false]);
        }

        if (array_key_exists('permission_ids', $validated)) {
            $role->permissions()->sync($validated['permission_ids']);
        }

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

        $role->update(collect($validated)->except('permission_ids')->all());

        if (array_key_exists('permission_ids', $validated)) {
            $role->permissions()->sync($validated['permission_ids']);
        }

        if (array_key_exists('is_default', $validated) && ! empty($validated['is_default'])) {
            Role::where('id', '!=', $role->id)->update(['is_default' => false]);
        }

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
    public function destroy($id): JsonResponse
    {
        $role = Role::findOrFail($id);
        $role->delete();

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
}
