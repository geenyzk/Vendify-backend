<?php

namespace Database\Seeders;

use App\Models\Permission;
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
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['slug' => $permission['slug']], $permission);
        }
    }
}
