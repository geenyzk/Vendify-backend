<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $definitions = [
                'switch_account' => ['Switch Account', 'Switch into customer accounts for support'],
                'ai_manager' => ['AI Manager', 'Use the AI Manager'],
                'migrations' => ['Migrate DB', 'Run database migrations'],
                'manage_roles' => ['Manage Roles', 'Manage roles, ordinary permissions, and staff assignments'],
                'manage_system_roles' => ['Manage System Roles', 'Manage protected owner-level roles and permissions'],
            ];

            $permissions = [];
            foreach ($definitions as $slug => [$name, $description]) {
                $matches = Permission::where('slug', $slug)
                    ->orWhereIn('name', [$name, $slug])
                    ->orderByRaw('CASE WHEN slug = ? THEN 0 ELSE 1 END', [$slug])
                    ->get();
                $permission = $matches->first();
                if (!$permission) {
                    $permission = Permission::create(['slug' => $slug, 'name' => $name, 'description' => $description]);
                } else {
                    foreach ($matches->skip(1) as $duplicate) {
                        foreach (DB::table('permission_role')->where('permission_id', $duplicate->id)->pluck('role_id') as $roleId) {
                            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permission->id]);
                        }
                        $duplicate->delete();
                    }
                    $permission->update(['slug' => $slug, 'name' => $name, 'description' => $description]);
                }
                $permissions[$slug] = $permission->id;
            }

            $owner = Role::where('slug', 'owner')->first();
            if ($owner) { $owner->forceFill(['is_staff' => true, 'is_active' => true])->save(); }
            $owner?->permissions()->syncWithoutDetaching(array_values($permissions));

            $coOwner = Role::where('slug', 'co-owner')->first();
            if ($coOwner) { $coOwner->forceFill(['is_staff' => true])->save(); }
            $coOwner?->permissions()->syncWithoutDetaching([
                $permissions['switch_account'], $permissions['ai_manager'],
                $permissions['migrations'], $permissions['manage_roles'],
            ]);

            $customerCare = Role::where('slug', 'customer-care')->first();
            if ($customerCare) { $customerCare->forceFill(['is_staff' => true])->save(); }
            $customerCare?->permissions()->detach($permissions['switch_account']);

            // Keep legacy Admin as operational staff without owner-level
            // impersonation or authorization controls.
            $admin = Role::where('slug', 'admin')->orWhere('name', 'Admin')->first();
            if ($admin) { $admin->forceFill(['is_staff' => true])->save(); }
            $admin?->permissions()->detach([
                $permissions['switch_account'], $permissions['migrations'], $permissions['manage_roles'],
            ]);
            $admin?->permissions()->syncWithoutDetaching([$permissions['ai_manager']]);
        });
    }

    public function down(): void
    {
        // Deliberately non-destructive: these permission rows or assignments
        // may be in active use by the time a rollback occurs.
    }
};
