<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use App\Services\Auth\SessionSecurityService;
use App\Support\AuditLogger;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        app(SessionSecurityService::class)->revokeAllForUser($request->user(), 'password_changed');
        AuditLogger::record('password_changed', subject: $request->user(), actor: $request->user(), description: 'Password changed; all active sessions were revoked.');

        return back()->with('status', 'password-updated');
    }
}
