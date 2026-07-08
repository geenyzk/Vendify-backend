<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    // Backs PUT /account/profile (routes/api.php). username/email are
    // accepted alongside fullname/phone so the default-owner bootstrap
    // account (admin@default.com, created by /setup) can rotate its
    // identity from the forced first-login modal in the SPA.
    public function updateProfile(Request $request)
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'fullname' => ['sometimes', 'required', 'string', 'max:255'],
                'phone' => ['sometimes', 'required', 'string', 'max:20', 'unique:users,phone,' . $user->id],
                'username' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', 'unique:users,username,' . $user->id],
                'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            ]);

            $user->fill($validated)->save();

            return $this->success(['user' => $user->fresh()], 'Profile updated.');
        } catch (ValidationException $e) {
            return $this->fail($e->errors(), 'Validation Error', 422);
        }
    }

    public function updatePassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ]);

            $user = $request->user();

            // Manual check instead of the `current_password` validation rule:
            // that rule reads the default (web) guard, which is empty when the
            // request is authenticated by a Sanctum bearer token.
            if (!Hash::check($validated['current_password'], $user->password)) {
                return $this->fail(
                    ['current_password' => ['Current password is incorrect.']],
                    'Validation Error',
                    422
                );
            }

            $user->forceFill([
                'password' => Hash::make($validated['password']),
            ])->save();

            return $this->success(null, 'Password updated.');
        } catch (ValidationException $e) {
            return $this->fail($e->errors(), 'Validation Error', 422);
        }
    }

    public function updatePin(Request $request)
    {
        try {
            $validated = $request->validate([
                'pin' => ['required', 'digits:4', 'confirmed'],
                'current_pin' => ['nullable', 'digits:4'],
            ]);

            $user = $request->user();

            if ($user->pin && ($validated['current_pin'] ?? null) !== $user->pin) {
                return $this->fail(
                    ['current_pin' => ['Current PIN is incorrect.']],
                    'Validation Error',
                    422
                );
            }

            $user->forceFill([
                'pin' => $validated['pin'],
            ])->save();

            return $this->success(null, 'Transaction PIN saved.');
        } catch (ValidationException $e) {
            return $this->fail($e->errors(), 'Validation Error', 422);
        }
    }
}
