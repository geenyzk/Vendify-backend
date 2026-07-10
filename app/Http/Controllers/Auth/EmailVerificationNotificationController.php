<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            if ($request->wantsJson() || $request->expectsJson()) {
                return response()->json(['message' => 'Email already verified.'], 200);
            }

            return redirect()->intended(route('dashboard', absolute: false));
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::warning('Failed to resend email verification notification', [
                'user_id' => $request->user()->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            if ($request->wantsJson() || $request->expectsJson()) {
                return response()->json([
                    'message' => 'We could not send the email. Please try again.',
                ], 500);
            }

            return back()->withErrors(['email' => 'We could not send the email. Please try again.']);
        }

        if ($request->wantsJson() || $request->expectsJson()) {
            return response()->json(['message' => 'Verification email sent.'], 200);
        }

        return back()->with('status', 'verification-link-sent');
    }
}
