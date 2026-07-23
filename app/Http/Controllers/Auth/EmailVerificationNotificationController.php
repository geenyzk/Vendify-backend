<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailVerificationNotificationController extends Controller
{
    use HttpResponse;

    /**
     * Send a new email verification notification.
     */
    public function store(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            if ($request->wantsJson() || $request->expectsJson()) {
                return $this->success(null, 'Email already verified.');
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
                return $this->fail(
                    null,
                    'We could not send the verification email. Please try again or contact support.',
                    503,
                );
            }

            return back()->withErrors(['email' => 'We could not send the email. Please try again.']);
        }

        if ($request->wantsJson() || $request->expectsJson()) {
            return $this->success(null, 'Verification email sent.');
        }

        return back()->with('status', 'verification-link-sent');
    }
}
