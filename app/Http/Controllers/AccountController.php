<?php

namespace App\Http\Controllers;

use App\Classes\Payment\Payment;
use App\HttpResponse;
use App\Models\Bank;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    use HttpResponse;

    /**
     * Update the authenticated user's own profile (name/phone only —
     * email and username double as login identifiers and aren't editable here).
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'fullname' => 'sometimes|string|max:255',
            'username' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($user->id)->whereNull('deleted_at'),
            ],
            'email' => [
                'sometimes',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at'),
            ],
            'phone' => [
                'sometimes',
                'string',
                Rule::unique('users', 'phone')->ignore($user->id)->whereNull('deleted_at'),
            ],
        ]);

        $user->update($validated);

        return $this->success(['user' => $user->fresh()->load('role.permissions')]);
    }

    /**
     * Change the authenticated user's own password.
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);

        return $this->success(null, 'Password updated successfully.');
    }

    /**
     * Set or change the authenticated user's transaction PIN. Only requires
     * the current PIN if one is already set (so the post-registration
     * "create your PIN" step doesn't need a current_pin field).
     */
    public function updatePin(Request $request)
    {
        $user = $request->user();

        // The web client uses camelCase form state, while the API contract is
        // snake_case. Normalize aliases here so a valid current PIN is not
        // reported as "required" simply because it arrived as currentPin.
        $request->merge([
            'pin_confirmation' => $request->input('pin_confirmation', $request->input('pinConfirmation')),
            'current_pin' => $request->input('current_pin', $request->input('currentPin')),
        ]);

        $validated = $request->validate([
            'pin' => ['required', 'digits:4'],
            'pin_confirmation' => ['required', 'same:pin'],
        ]);

        if ($user->pin) {
            // Creating a PIN is auto-submitted as soon as the fourth
            // confirmation digit is entered. A double tap/key repeat can send
            // an identical retry after the first request has already saved
            // the PIN. Treat that retry as success; it changes nothing and
            // avoids incorrectly asking a brand-new user for a current PIN.
            if (!$request->filled('current_pin') && Hash::check($validated['pin'], $user->pin)) {
                return $this->pinUpdatedResponse($user);
            }

            $currentPin = $request->validate([
                'current_pin' => ['required', 'digits:4'],
            ])['current_pin'];

            if (!Hash::check($currentPin, $user->pin)) {
                return $this->fail(['current_pin' => ['Current PIN is incorrect.']], 'Current PIN is incorrect.', 422);
            }
        }

        $user->update(['pin' => $validated['pin']]);

        return $this->pinUpdatedResponse($user);
    }

    private function pinUpdatedResponse(User $user): JsonResponse
    {
        // PIN setup is also used immediately after registration, before any
        // funding provider may have been configured. Return the lightweight
        // auth payload; serializing the default User appends would evaluate
        // Bank accessors and can dereference a missing provider's charge_type.
        $freshUser = $user->fresh()->load('role.permissions');
        $freshUser->setAppends(['has_pin']);

        return $this->success(
            ['user' => $freshUser],
            'Transaction PIN updated successfully.'
        );
    }

    /**
     * (Re)generate the authenticated user's virtual account(s) — one per
     * currently active payment provider. Normally done automatically at
     * register/login, but generation can fail silently (provider outage, no
     * active provider at the time) or a provider can be activated later, so
     * the wallet page offers this as a manual retry. Safe to call
     * repeatedly: PaymentBase::generateAccount() skips any provider a Bank
     * row already exists for.
     */
    public function generateVirtualAccounts(Request $request)
    {
        $user = $request->user();

        Payment::generateAccount($user);

        return $this->success(['user' => $user->fresh()->load('role.permissions')]);
    }

    public function fundingAccount(Request $request)
    {
        $user = $request->user();
        $find = fn () => Bank::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNotNull('bank_account')
            ->whereNotNull('bank_name')
            ->whereIn(DB::raw("LOWER(REPLACE(provider, ' ', ''))"), Provider::query()
                ->getPaymentProviders()
                ->selectRaw("LOWER(REPLACE(name, ' ', ''))"))
            ->latest('id')->first();

        $account = $find();
        if (!$account) {
            Payment::generateAccount($user);
            $account = $find();
        }

        if (!$account) {
            return response()->json([
                'status' => 'failed',
                'message' => 'We could not create your funding account. Check that your profile identity details are complete, then retry.',
                'retryable' => true,
            ]);
        }

        return response()->json([
            'status' => 'ready',
            'account' => [
                'account_number' => $account->bank_account,
                'bank_name' => $account->bank_name,
                'account_name' => $account->account_name,
                'currency' => $account->currency ?: 'NGN',
            ],
        ]);
    }
}
