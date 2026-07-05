<?php

namespace App\Http\Controllers;

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
            'phone' => [
                'sometimes',
                'string',
                Rule::unique('users', 'phone')->ignore($user->id),
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
}
