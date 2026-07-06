<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

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

        // Reuses the Discount table under service_type "user_upgrade": the
        // upgrade tier name lives in `network`, and the flat cost lives in
        // `value` (expected discount_type "fixed").
        $discount = Discount::where('service_type', 'user_upgrade')->where('network', $upgradeTo)->first();
        if (!$discount) {
            return response()->json(['error' => 'Discount info not found.'], 404);
        }

        $cost = (float) $discount->value;

        if ($user->wallet_balance < $cost) {
            return response()->json(['error' => 'Insufficient wallet balance. Please fund your wallet.'], 402);
        }

        // Deduct the cost from the user's wallet
        $user->wallet_balance -= $cost;
        $user->user_type = $upgradeTo;
        $user->save();

        return response()->json([
            'message' => "Successfully upgraded your account to {$upgradeTo}.",
            'user' => $user,
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
}
