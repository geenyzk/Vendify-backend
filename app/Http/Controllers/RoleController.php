<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use HttpResponse;

    public function index()
    {
        return $this->success(['roles' => Role::with('permissions')->get()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'is_staff' => 'boolean',
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'is_staff' => $validated['is_staff'] ?? false,
        ]);

        $role->permissions()->sync(
            Permission::whereIn('name', $validated['permissions'] ?? [])->pluck('id')
        );

        return $this->success(['role' => $role->load('permissions')], 'Role created', 201);
    }

    public function show(string $id)
    {
        $role = Role::with('permissions')->find($id);

        if (!$role) {
            return $this->fail(null, 'Role not found', 404);
        }

        return $this->success(['role' => $role]);
    }

    public function update(Request $request, string $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return $this->fail(null, 'Role not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:roles,name,' . $role->id,
            'is_staff' => 'boolean',
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role->update([
            'name' => $validated['name'] ?? $role->name,
            'is_staff' => $validated['is_staff'] ?? $role->is_staff,
        ]);

        if (array_key_exists('permissions', $validated)) {
            $role->permissions()->sync(
                Permission::whereIn('name', $validated['permissions'])->pluck('id')
            );
        }

        return $this->success(['role' => $role->load('permissions')], 'Role updated');
    }

    public function destroy(string $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return $this->fail(null, 'Role not found', 404);
        }

        $role->delete();

        return $this->success([], 'Role deleted');
    }

    public function users(string $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return $this->fail(null, 'Role not found', 404);
        }

        return $this->success(['users' => $role->users]);
    }
}
