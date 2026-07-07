<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\Role;
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
}
