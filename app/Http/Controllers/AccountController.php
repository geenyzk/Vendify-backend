<?php

namespace App\Http\Controllers;

use App\Classes\Payment\Payment;
use App\HttpResponse;
use Illuminate\Http\Request;
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

        $rules = [
            'pin' => ['required', 'digits:4'],
            'pin_confirmation' => ['required', 'same:pin'],
        ];
        if ($user->pin) {
            $rules['current_pin'] = ['required', 'digits:4'];
        }

        $validated = $request->validate($rules);

        if ($user->pin && !Hash::check($validated['current_pin'], $user->pin)) {
            return $this->fail(['current_pin' => ['Current PIN is incorrect.']], 'Current PIN is incorrect.', 422);
        }

        $user->update(['pin' => $validated['pin']]);

        return $this->success(
            ['user' => $user->fresh()->load('role.permissions')],
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
}
