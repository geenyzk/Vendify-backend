<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            if ($request->wantsJson() || $request->expectsJson()) {
                error_log("Email already verified");
                return response()->json(['message' => 'Email already verified'], 200);
            }

            return redirect()->intended(route('dashboard', absolute: false));
        }

        $request->user()->sendEmailVerificationNotification();

        if ($request->wantsJson() || $request->expectsJson()) {
            error_log("verification link sent");
            return response()->json(['message' => 'verification-link-sent'], 200);
        }
            error_log("verification link sent 2");

        return back()->with('status', 'verification-link-sent');
    }
}
