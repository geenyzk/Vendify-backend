<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Register the `ai_manager` permission and grant it to the admin role, so the
 * capability exists on already-migrated installs without re-running seeders.
 * PermissionSeeder carries the same entry for fresh installs. See the admin
 * route group gated by `permission:ai_manager`.
 */
return new class extends Migration {
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['slug' => 'ai_manager'],
            [
                'name' => 'AI Manager',
                'description' => 'Use the AI Manager assistant to monitor the site and propose admin actions',
            ]
        );

        Role::where('slug', 'admin')->first()
            ?->permissions()->syncWithoutDetaching([$permission->id]);
    }

    public function down(): void
    {
        Permission::where('slug', 'ai_manager')->delete();
    }
};
