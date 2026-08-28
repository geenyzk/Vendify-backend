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
            $permissions = Permission::whereIn('slug', [
                'switch_account', 'migrations', 'manage_roles',
            ])->get()->keyBy('slug');

            $switchAccountId = $permissions->get('switch_account')?->id;
            if ($switchAccountId) {
                Role::whereNotIn('slug', Role::PROTECTED_SLUGS)->get()
                    ->each(fn (Role $role) => $role->permissions()->detach($switchAccountId));
            }

            $admin = Role::where('slug', 'admin')->orWhere('name', 'Admin')->first();
            if ($admin) {
                $admin->permissions()->detach(
                    $permissions->only(['migrations', 'manage_roles'])->pluck('id')
                );
            }

            $ownerCapabilityIds = $permissions->pluck('id')->all();
            Role::whereIn('slug', Role::PROTECTED_SLUGS)->get()
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching($ownerCapabilityIds));
        });
    }

    public function down(): void
    {
        // Restoring privileged grants on rollback would weaken the policy.
    }
};
