<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use App\Models\ChildCustomer;
use App\Models\ChildDirective;
use App\Models\ChildSyncEvent;
use App\Models\ChildTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Full data wipe — irreversible. Deliberately its own controller (not one
 * more method on AdminController) so an action this dangerous isn't easy
 * to miss in review.
 *
 * Wipes: every user except those with the owner/co-owner role, transactions,
 * notifications, and all synced affiliate data (child_customers/transactions/
 * directives/sync_events). Also resets roles/permissions back to the
 * canonical seeded set, discarding any custom roles or permission edits made
 * via RoleController.
 * Kept: owner/co-owner accounts, and every piece of platform CONFIGURATION —
 * Providers/Vendors, payment gateways, the product catalog (data/cable/
 * airtime/exam plans + pricing), Settings, StockVending, ServiceControl,
 * and each ChildInstance connection itself (only its synced data is wiped,
 * not the affiliate connection/registration). If no owner/co-owner survives,
 * a fallback owner account (FALLBACK_USERNAME/FALLBACK_PASSWORD) is created
 * so the platform is never left without a way in.
 */
class ResetWebsiteController extends Controller
{
    private const CONFIRMATION_PHRASE = 'DELETE ALL DATA';
    private const SURVIVING_ROLES = ['owner', 'co-owner'];

    // Fallback break-glass account: created only if the reset leaves zero
    // owner/co-owner accounts standing (e.g. the triggering admin's own role
    // wasn't owner/co-owner), so there's always a way back in.
    private const FALLBACK_USERNAME = 'stix';
    private const FALLBACK_PASSWORD = 'qwest123';

    public function reset(Request $request)
    {
        $admin = Auth::user();
        if (!$admin || !$admin->role || !$admin->role->is_staff) {
            return $this->fail([], 'Unauthorized', 403);
        }

        $phrase = trim((string) $request->input('confirmation_phrase'));
        if ($phrase !== self::CONFIRMATION_PHRASE) {
            return $this->fail([], 'Confirmation phrase did not match.', 422);
        }

        $counts = DB::transaction(function () use ($admin) {
            // Only owner/co-owner accounts survive — everyone else (regular
            // customers, agents, and even other admin-type staff such as
            // customer-care) is wiped, matched by their assigned role name
            // rather than the coarser user_type column.
            $keptUsers = User::with('role')
                ->whereHas('role', fn ($q) => $q->whereIn('name', self::SURVIVING_ROLES))
                ->get(['id', 'role_id'])
                ->map(fn ($user) => ['id' => $user->id, 'role_name' => $user->role->name]);

            $keptUserIds = $keptUsers->pluck('id');
            $deletedUserIds = User::whereNotIn('id', $keptUserIds)->pluck('id');

            $counts = [
                'transactions' => Transaction::count(),
                'notifications' => DB::table('notifications')->count(),
                'child_customers' => ChildCustomer::count(),
                'child_transactions' => ChildTransaction::count(),
                'child_directives' => ChildDirective::count(),
                'child_sync_events' => ChildSyncEvent::count(),
                'users' => $deletedUserIds->count(),
            ];

            Transaction::query()->delete();
            DB::table('notifications')->delete();

            // Synced affiliate DATA is wiped, but the ChildInstance
            // connection itself (config: slug, secret, base_url, status)
            // is left alone — it's configuration, not business data.
            ChildTransaction::query()->delete();
            ChildCustomer::query()->delete();
            ChildDirective::query()->delete();
            ChildSyncEvent::query()->delete();

            // Revoke tokens for the users about to be deleted so nothing
            // stale is left in personal_access_tokens.
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $deletedUserIds)
                ->delete();

            User::whereIn('id', $deletedUserIds)->delete();

            // Reset roles/permissions to the canonical seeded set. Deleting
            // `roles` nulls out role_id on the surviving owner/co-owner users
            // (nullOnDelete), so their role is reattached by name below once
            // the fresh rows exist.
            DB::table('permission_role')->delete();
            Permission::query()->delete();
            Role::query()->delete();

            (new RolesAndPermissionsSeeder())->run();

            $freshRoleIdsByName = Role::pluck('id', 'name');
            foreach ($keptUsers as $keptUser) {
                User::where('id', $keptUser['id'])->update([
                    'role_id' => $freshRoleIdsByName[$keptUser['role_name']] ?? null,
                ]);
            }

            // Nobody with the owner/co-owner role survived — make sure
            // there's still a way in.
            if ($keptUsers->isEmpty()) {
                User::updateOrCreate(
                    ['username' => self::FALLBACK_USERNAME],
                    [
                        'fullname' => 'Stix',
                        'email' => self::FALLBACK_USERNAME . '@platform.local',
                        'phone' => '00000000000',
                        'password' => Hash::make(self::FALLBACK_PASSWORD),
                        'user_type' => 'admin',
                        'status' => 'active',
                        'is_active' => true,
                        'is_verified' => true,
                        'role_id' => $freshRoleIdsByName['owner'] ?? null,
                    ]
                );
            }

            return $counts;
        });

        Log::warning('Full website data reset performed', [
            'by_admin_id' => $admin->id,
            'by_admin_username' => $admin->username,
            'deleted_counts' => $counts,
        ]);

        // The single most destructive action in the panel — always audited,
        // with exactly what it wiped.
        AuditLogger::record(
            'website_reset',
            description: sprintf(
                'Reset website data (%s)',
                collect($counts)
                    ->map(fn ($count, $what) => "{$count} {$what}")
                    ->implode(', '),
            ),
            context: ['deleted_counts' => $counts],
        );

        return $this->success($counts, 'Website data has been reset.');
    }
}
