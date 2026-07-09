<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permission keys match the `permission:xxx` middleware groups already
     * wired up in routes/api.php's /admin prefix (plus switch_account, which
     * only the SPA reads — it gates the admin↔customer view toggle).
     *
     * Legacy databases already hold these permissions with a display `name`
     * ("Customers") and machine `slug` ("customers"), so lookups here match
     * slug-or-name and Role::hasPermission accepts either — rows are adopted,
     * never duplicated.
     */
    private const PERMISSIONS = [
        'customers',
        'settings',
        'transactions',
        'wallets',
        'support',
        'airtime_to_cash',
        'switch_account',
    ];

    /**
     * Staff roles get permission sets; the rest are customer-facing tiers
     * (mirroring user_type) that never touch the admin panel. Keyed by slug
     * so legacy rows (e.g. "customer care" / slug customer-care) are updated
     * in place.
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
        $permissions = collect(self::PERMISSIONS)->mapWithKeys(function (string $key) {
            $permission = Permission::where('slug', $key)->orWhere('name', $key)->first();

            if (!$permission) {
                $permission = Permission::create(['name' => $key, 'slug' => $key]);
            } elseif (!$permission->slug) {
                $permission->update(['slug' => $key]);
            }

            return [$key => $permission];
        });

        foreach (self::ROLES as $slug => $config) {
            $role = Role::where('slug', $slug)->orWhere('name', $slug)->first();

            $isDefault = $slug === 'basic';
            if (!$role) {
                $role = Role::create([
                    'name' => $slug,
                    'slug' => $slug,
                    'is_staff' => $config['is_staff'],
                    'is_default' => $isDefault,
                ]);
            } else {
                $role->update([
                    'slug' => $role->slug ?: $slug,
                    'is_staff' => $config['is_staff'],
                    'is_default' => $isDefault,
                ]);
            }

            $role->permissions()->sync(
                collect($config['permissions'])->map(fn (string $key) => $permissions[$key]->id)
            );
        }
    }
}
