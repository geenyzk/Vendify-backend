<?php

namespace App\Http\Controllers;

use App\HttpResponse;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    use HttpResponse;

    /**
     * Roles the seeder/legacy data rely on — deleting these would orphan
     * pricing tiers or lock every staff member out of the panel (users.role_id
     * is nullOnDelete, so deleting `owner` silently de-staffs its holders).
     */
    private const PROTECTED_SLUGS = [
        'owner', 'co-owner', 'customer-care', 'admin', 'user',
        'api', 'agent', 'bonanza', 'basic',
    ];

    public function index()
    {
        return $this->success([
            'roles' => Role::with('permissions')->withCount('users')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $role = Role::create($this->roleAttributes($validated) + [
            'slug' => $this->uniqueSlug($validated['slug'] ?? $validated['name']),
        ]);

        $this->syncPermissions($role, $validated);

        return $this->success([
            'role' => $role->load('permissions')->loadCount('users'),
        ], 'Role created', 201);
    }

    public function show(string $id)
    {
        $role = Role::with('permissions')->withCount('users')->find($id);

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

        $validated = $this->validatePayload($request, $role);

        $attributes = $this->roleAttributes($validated);
        if (array_key_exists('slug', $validated) && $validated['slug']) {
            $attributes['slug'] = $this->uniqueSlug($validated['slug'], $role->id);
        }

        $role->update($attributes);

        $this->syncPermissions($role, $validated);

        return $this->success([
            'role' => $role->load('permissions')->loadCount('users'),
        ], 'Role updated');
    }

    public function destroy(string $id)
    {
        $role = Role::withCount('users')->find($id);

        if (!$role) {
            return $this->fail(null, 'Role not found', 404);
        }

        if (in_array($role->slug, self::PROTECTED_SLUGS, true)) {
            return $this->fail(null, 'This is a built-in role and cannot be deleted.', 422);
        }

        if ($role->users_count > 0) {
            return $this->fail(null, "Reassign the {$role->users_count} user(s) on this role first.", 422);
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

    private function validatePayload(Request $request, ?Role $role = null): array
    {
        $ignore = $role ? ',' . $role->id : '';

        return $request->validate([
            'name' => ($role ? 'sometimes|' : '') . 'required|string|max:255|unique:roles,name' . $ignore,
            'slug' => 'sometimes|nullable|string|max:255|unique:roles,slug' . $ignore,
            'description' => 'sometimes|nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
            'is_staff' => 'sometimes|boolean',
            'upgradable' => 'sometimes|boolean',
            'upgrade_cost' => 'sometimes|nullable|numeric|min:0',
            // The SPA sends permission ids; older API clients send names.
            'permission_ids' => 'sometimes|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string',
        ]);
    }

    private function roleAttributes(array $validated): array
    {
        return collect($validated)
            ->only(['name', 'description', 'is_active', 'is_staff', 'upgradable', 'upgrade_cost'])
            ->all();
    }

    private function syncPermissions(Role $role, array $validated): void
    {
        if (array_key_exists('permission_ids', $validated)) {
            $role->permissions()->sync($validated['permission_ids']);

            return;
        }

        if (array_key_exists('permissions', $validated)) {
            // Legacy shape: permission identifiers by name or slug (the two
            // columns hold the machine key on new and legacy rows respectively).
            $role->permissions()->sync(
                Permission::whereIn('name', $validated['permissions'])
                    ->orWhereIn('slug', $validated['permissions'])
                    ->pluck('id')
            );
        }
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'role';
        $slug = $base;

        for ($i = 2; Role::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
