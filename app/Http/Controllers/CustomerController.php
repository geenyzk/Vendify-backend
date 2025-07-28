<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{

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

        // Deduct the cost from the user's wallet
        $user->wallet_balance -= $cost;
        $user->user_type = $upgradeTo;
        $user->save();

        return response()->json([
            'message' => "Successfully upgraded your account to {$upgradeTo}.",
            'user' => $user,
        ]);
    }
}
