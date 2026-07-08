<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\HttpResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    use HttpResponse;

    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status == Password::RESET_LINK_SENT
                    ? back()->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }

    /**
     * JSON counterpart of store() for the SPA — same broker call, just a
     * JSON response instead of a redirect. Always reports success even when
     * the email isn't found, so this can't be used to enumerate accounts.
     */
    public function apiStore(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // The status (sent / throttled / user not found) is intentionally
        // not surfaced — always report success so this can't be used to
        // enumerate which emails are registered. A transport failure (bad
        // SMTP config, provider outage) must not surface as a 500 either.
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            Log::warning('Failed to send password reset link', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->success(null, 'If an account exists for that email, a reset link has been sent.');
    }
}
