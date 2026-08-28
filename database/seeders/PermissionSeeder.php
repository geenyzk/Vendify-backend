<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the application's default permissions.
     */
    public function run(): void
    {
        $permissions = [
            ['name' => 'Customers', 'slug' => 'customers', 'description' => 'Manage customer accounts'],
            ['name' => 'Wallets', 'slug' => 'wallets', 'description' => 'Manage wallet balances and funding'],
            ['name' => 'Transactions', 'slug' => 'transactions', 'description' => 'View and manage transactions'],
            ['name' => 'Support', 'slug' => 'support', 'description' => 'Handle support tickets and messages'],
            ['name' => 'Settings', 'slug' => 'settings', 'description' => 'Manage platform settings'],
            ['name' => 'Switch Account', 'slug' => 'switch_account', 'description' => 'Switch between the admin panel and the customer-facing view'],
            ['name' => 'Airtime to Cash', 'slug' => 'airtime_to_cash', 'description' => 'Review and approve or reject airtime-to-cash conversion requests'],
            ['name' => 'Audit Log', 'slug' => 'audit_logs', 'description' => 'View the audit trail of admin actions and data changes'],
            ['name' => 'AI Manager', 'slug' => 'ai_manager', 'description' => 'Use the AI Manager assistant to monitor the site and propose admin actions'],
            ['name' => 'Migrate DB', 'slug' => 'migrations', 'description' => 'Run database migrations from the admin interface'],
            ['name' => 'Manage Roles', 'slug' => 'manage_roles', 'description' => 'Create roles, assign ordinary permissions, and manage staff assignments'],
            ['name' => 'Manage System Roles', 'slug' => 'manage_system_roles', 'description' => 'Manage protected owner-level roles and permissions'],
        ];

        $ids = [];
        foreach ($permissions as $permission) {
            $model = Permission::where('slug', $permission['slug'])
                ->orWhere('name', $permission['name'])
                ->orWhere('name', $permission['slug'])
                ->first();
            if (!$model) {
                $model = Permission::create($permission);
            } else {
                $model->update($permission);
            }
            $ids[] = $model->id;
        }

        // The admin role gets every permission by default — other roles can
        // be granted individual permissions later via the Roles & Permissions
        // admin UI.
        $adminRole = Role::where('slug', 'admin')->first();
        $protectedId = Permission::where('slug', 'manage_system_roles')->value('id');
        $adminRole?->permissions()->syncWithoutDetaching(array_values(array_diff($ids, [$protectedId])));
    }
}
