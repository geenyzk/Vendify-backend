<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Repair the canonical staff roles in place. User role_id values
            // are deliberately preserved; this only restores the structural
            // flags used by the staff middleware and frontend route guard.
            $staffRoles = Role::query()
                ->where(function ($query) {
                    $query->whereIn('slug', ['owner', 'co-owner', 'admin', 'customer-care'])
                        ->orWhereIn('name', [
                            'Owner', 'owner', 'Co-owner', 'co-owner',
                            'Admin', 'admin', 'Customer Care', 'customer-care',
                        ]);
                })
                ->get();

            foreach ($staffRoles as $role) {
                $role->forceFill(['is_staff' => true, 'is_active' => true])->save();
            }

            $staffRoleIds = $staffRoles->pluck('id')->all();
            if ($staffRoleIds !== []) {
                // Retained only for compatibility/reporting. Authorization
                // continues to use role.is_staff, never user_type.
                DB::table('users')
                    ->whereIn('role_id', $staffRoleIds)
                    ->update(['user_type' => 'admin']);
            }
        });
    }

    public function down(): void
    {
        // Do not deactivate roles or demote existing staff on rollback.
    }
};
