<?php

namespace App\Http\Controllers;

use App\Models\ChildCustomer;
use App\Models\ChildDirective;
use App\Models\ChildSyncEvent;
use App\Models\ChildTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Full data wipe — irreversible. Deliberately its own controller (not one
 * more method on AdminController) so an action this dangerous isn't easy
 * to miss in review.
 *
 * Wipes: non-admin users, transactions, notifications, and all synced
 * affiliate data (child_customers/transactions/directives/sync_events).
 * Kept: admin user accounts, and every piece of platform CONFIGURATION —
 * Providers/Vendors, payment gateways, the product catalog (data/cable/
 * airtime/exam plans + pricing), Settings, StockVending, ServiceControl,
 * and each ChildInstance connection itself (only its synced data is wiped,
 * not the affiliate connection/registration).
 */
class ResetWebsiteController extends Controller
{
    private const CONFIRMATION_PHRASE = 'DELETE ALL DATA';

    public function reset(Request $request)
    {
        $admin = Auth::user();
        if (!$admin || $admin->user_type !== 'admin') {
            return $this->fail([], 'Unauthorized', 403);
        }

        $phrase = trim((string) $request->input('confirmation_phrase'));
        if ($phrase !== self::CONFIRMATION_PHRASE) {
            return $this->fail([], 'Confirmation phrase did not match.', 422);
        }

        $counts = DB::transaction(function () use ($admin) {
            $nonAdminUserIds = User::where('user_type', '!=', 'admin')->pluck('id');

            $counts = [
                'transactions' => Transaction::count(),
                'notifications' => DB::table('notifications')->count(),
                'child_customers' => ChildCustomer::count(),
                'child_transactions' => ChildTransaction::count(),
                'child_directives' => ChildDirective::count(),
                'child_sync_events' => ChildSyncEvent::count(),
                'users' => $nonAdminUserIds->count(),
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
                ->whereIn('tokenable_id', $nonAdminUserIds)
                ->delete();

            User::where('user_type', '!=', 'admin')->delete();

            return $counts;
        });

        Log::warning('Full website data reset performed', [
            'by_admin_id' => $admin->id,
            'by_admin_username' => $admin->username,
            'deleted_counts' => $counts,
        ]);

        return $this->success($counts, 'Website data has been reset.');
    }
}
