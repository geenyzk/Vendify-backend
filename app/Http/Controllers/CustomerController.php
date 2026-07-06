<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\Role;
<<<<<<< HEAD
=======
use App\Models\Setting;
use App\Models\Transaction;
>>>>>>> edbac78 (feat: Add in-app notifications for wallet transactions and airtime-to-cash requests, including admin alerts and user notifications)
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{


    /**
 * Convert Referral Balance to Wallet
 *
 * @group Customer
 *
 * This endpoint converts a user's referral bonus balance into their wallet balance.
 * The entire referral balance will be moved to the wallet if it's greater than zero.
 *
 * @urlParam id integer required The ID of the user. Example: 5
 *
 * @response 200 {
 *   "message": "Referral balance converted successfully.",
 *   "user": {
 *     "id": 5,
 *     "name": "Jane Doe",
 *     "email": "jane@example.com",
 *     "wallet_balance": 1000.00,
 *     "referral_balance": 0
 *   }
 * }
 *
 * @response 422 {
 *   "message": "Referral balance is zero or insufficient."
 * }
 *
 * @response 404 {
 *   "message": "User not found."
 * }
 *
 * @authenticated
 */



    public function convertReferralToWallet(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        if ($user->referral_balance <= 0) {
            return response()->json([
                'message' => 'Referral balance is zero or insufficient.',
            ], 422);
        }

        return DB::transaction(function () use ($user) {
            $amount = $user->referral_balance;
            $balanceBefore = $user->wallet_balance;
            $balanceAfter = $balanceBefore + $amount;

            $user->referral_balance = 0;
            $user->wallet_balance += floatval($amount);
            $user->save();

            return response()->json([
                'message' => 'Referral balance converted successfully.',
                "user" => $user
            ]);
        });
    }




    /**
 * Upgrade Customer Account
 *
 * @group Customer
 *
 * This endpoint allows an authenticated user to upgrade their account type (e.g., user, agent, bonanza, api).
 * The system checks for sufficient wallet balance, deducts the upgrade cost, and updates the user type.
 *
 * @bodyParam upgrade_to string required The user level to upgrade to. Must be one of: user, agent, bonanza, api. Example: agent
 *
 * @response 200 {
 *   "message": "Successfully upgraded your account to agent.",
 *   "user": {
 *     "id": 1,
 *     "name": "John Doe",
 *     "email": "john@example.com",
 *     "wallet_balance": 1500.00,
 *     "user_type": "agent"
 *   }
 * }
 *
 * @response 400 {
 *   "error": "You are already at this user level."
 * }
 *
 * @response 402 {
 *   "error": "Insufficient wallet balance. Please fund your wallet."
 * }
 *
 * @response 422 {
 *   "error": "Invalid upgrade option"
 * }
 *
 * @response 404 {
 *   "error": "Discount info not found."
 * }
 *
 * @authenticated
 */

    

    public function upgrade(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'upgrade_to' => 'required|string|in:user,agent,bonanza,api',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid upgrade option'], 422);
        }

        $upgradeTo = $request->input('upgrade_to');

        // Check if user already at this level
        if ($user->user_type === $upgradeTo) {
            return response()->json(['error' => 'You are already at this user level.'], 400);
        }

        $discount = Discount::whereName($upgradeTo)->first();
        if (!$discount) {
            return response()->json(['error' => 'Discount info not found.'], 404);
        }

        $cost = $discount->price;

        if ($user->wallet_balance < $cost) {
            return response()->json(['error' => 'Insufficient wallet balance. Please fund your wallet.'], 402);
        }

        // Deduct the cost from the user's wallet. user_type is kept in sync
        // for reporting (AdminController::stats, broadcast targeting), but
        // pricing itself reads role_id (User::pricingTier()) — the role
        // table has no "user" entry, it's named "basic" there, hence the
        // translation below.
        $user->wallet_balance -= $cost;
        $user->user_type = $upgradeTo;
        $user->role_id = Role::where('name', $upgradeTo === 'user' ? 'basic' : $upgradeTo)->value('id');
        $user->save();

        return response()->json([
            'message' => "Successfully upgraded your account to {$upgradeTo}.",
            'user' => $user,
        ]);
    }
<<<<<<< HEAD
=======

    /**
     * List the account tiers (roles) a user can upgrade to, with their cost.
     *
     * @group Customer
     * @authenticated
     */
    public function upgradeTiers(Request $request)
    {
        $user = Auth::user();

        $tiers = Role::where('upgradable', true)
            ->where('is_active', true)
            ->get(['id', 'name', 'slug', 'upgrade_cost'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'cost' => (float) $r->upgrade_cost,
            ]);

        return $this->success([
            'current_tier' => $user->role?->slug ?? $user->user_type,
            'current_tier_name' => $user->role?->name ?? ucfirst($user->user_type),
            'tiers' => $tiers,
        ]);
    }

    /**
     * Referral program summary for the authenticated user — their own code,
     * how many people they've referred, how many of those are still
     * "pending" (never completed a successful transaction, so no commission
     * has been earned from them yet), and their earnings. See
     * TransactionService::distributeCommission() for how referral_balance /
     * total_referral_earnings actually get credited.
     *
     * @group Customer
     * @authenticated
     */
    public function referralStats(Request $request)
    {
        $user = Auth::user();

        $totalReferrals = $user->referrals()->count();
        $pendingReferrals = $user->referrals()
            ->whereDoesntHave('transactions', fn ($q) => $q->where('status', 'success'))
            ->count();

        return $this->success([
            'referral_code' => $user->referral_code,
            'total_referrals' => $totalReferrals,
            'pending_referrals' => $pendingReferrals,
            'active_referrals' => $totalReferrals - $pendingReferrals,
            'referral_balance' => (float) $user->referral_balance,
            'total_earnings' => (float) $user->total_referral_earnings,
            'commission_rate' => (float) (Setting::first()?->referral_commission_rate ?? 2.00),
        ]);
    }


    /**
     * Return monthly transaction status counts for the authenticated user.
     *
     * @group Customer
     * @authenticated
     */
    public function stats(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return $this->fail([], 'Unauthenticated', 401);
        }

        $now = Carbon::now();

        $baseQuery = Transaction::where('user_id', $user->id)
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month);

        // Build a simple 5-day transactions chart for this user
        $days = collect();
        for ($i = 4; $i >= 0; $i--) {
            $days->push(Carbon::today()->subDays($i)->format('Y-m-d'));
        }

        $txData = Transaction::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as total')
        )
            ->where('user_id', $user->id)
            ->whereDate('created_at', '>=', $days->first())
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $labels = [];
        $values = [];
        foreach ($days as $day) {
            $labels[] = Carbon::parse($day)->format('D');
            $values[] = $txData[$day]->total ?? 0;
        }

        return $this->success([
            'monthly_successful' => (clone $baseQuery)->where('status', 'success')->count(),
            'monthly_pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'tx_chart' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Transactions',
                        'data' => $values,
                        'backgroundColor' => '#36A2EB',
                    ]
                ]
            ]
        ]);
    }
>>>>>>> edbac78 (feat: Add in-app notifications for wallet transactions and airtime-to-cash requests, including admin alerts and user notifications)
}
