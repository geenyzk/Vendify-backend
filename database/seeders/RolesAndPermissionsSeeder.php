<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permission names match the `permission:xxx` middleware groups already
     * wired up in routes/api.php's /admin prefix.
     */
    private const PERMISSIONS = [
        'customers',
        'settings',
        'transactions',
        'wallets',
        'support',
        'airtime_to_cash',
    ];

    /**
     * Staff roles get permission sets; the rest are customer-facing tiers
     * (mirroring user_type) that never touch the admin panel.
     */
    private const ROLES = [
        'owner' => ['is_staff' => true, 'permissions' => self::PERMISSIONS],
        'co-owner' => ['is_staff' => true, 'permissions' => self::PERMISSIONS],
        'customer-care' => ['is_staff' => true, 'permissions' => ['customers', 'support']],
        'api' => ['is_staff' => false, 'permissions' => []],
        'agent' => ['is_staff' => false, 'permissions' => []],
        'bonanza' => ['is_staff' => false, 'permissions' => []],
        'basic' => ['is_staff' => false, 'permissions' => []],
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->mapWithKeys(
            fn (string $name) => [$name => Permission::firstOrCreate(['name' => $name])]
        );

        foreach (self::ROLES as $name => $config) {
            $role = Role::updateOrCreate(
                ['name' => $name],
                ['is_staff' => $config['is_staff']]
            );

            $role->permissions()->sync(
                collect($config['permissions'])->map(fn (string $name) => $permissions[$name]->id)
            );
        }
    }
}
