<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs customers left role-less by the old registration bug.
 *
 * Registration called assignRole('user'), but no role is named/slugged 'user',
 * so it silently no-op'd and those customers kept role_id = null. A role-less
 * customer has no role-specific data-plan pricing, so every plan resolved to no
 * price and displayed as ₦0.00 for them — old and new customers alike.
 *
 * Assign the configured default role (is_default — 'basic') to every user that
 * has no role. Users who already have a real role (staff, agents, admins set up
 * through the admin UI) are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaultRoleId = DB::table('roles')->where('is_default', true)->orderBy('id')->value('id');

        if (!$defaultRoleId) {
            // No default role configured — nothing safe to assign. Seed roles
            // first (RolesAndPermissionsSeeder marks 'basic' as default).
            return;
        }

        // Scope tightly to the exact population the registration bug produced:
        // a default customer (user_type 'user' — the column default that
        // registration never overrode) left with no role. This deliberately
        // does NOT touch a staff/admin/agent that happens to be role-less, so
        // the backfill can never demote a privileged account to 'basic'.
        DB::table('users')
            ->whereNull('role_id')
            ->where('user_type', 'user')
            ->update(['role_id' => $defaultRoleId]);
    }

    public function down(): void
    {
        // Not reversible: we can't tell which users were originally role-less
        // versus deliberately assigned the default role, and clearing them
        // would re-introduce the ₦0.00 bug. Intentionally a no-op.
    }
};
