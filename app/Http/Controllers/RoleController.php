<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /**
     * Get all roles.
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('serviceCostMargins', 'users')->get();

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
        $role = Role::with('serviceCostMargins', 'users')->findOrFail($id);

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
            'is_active' => 'boolean',
        ]);

        $role = Role::create($validated);

        return response()->json([
            'success' => true,
            'data' => $role,
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
            'name' => 'string|unique:roles,name,' . $id,
            'slug' => 'string|unique:roles,slug,' . $id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $role->update($validated);

        return response()->json([
            'success' => true,
            'data' => $role,
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
