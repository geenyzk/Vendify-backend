<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\HttpResponse;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class VerifyEmailController extends Controller
{
    use HttpResponse;

    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        Log::info("__invoked");
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
        // If multiple URLs are set, use the first one
        if (str_contains($frontendUrl, ',')) {
            $frontendUrl = explode(',', $frontendUrl)[0];
        }
        $redirectUrl = rtrim($frontendUrl, '/') . '/verify-email?verified=1';

        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->away($redirectUrl);
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->away($redirectUrl);
    }

    /**
     * Verify email via code (API endpoint for frontend)
     * This endpoint allows users to verify their email by entering a code
     * sent to their email address.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|min:6|max:6'
        ]);

        $user = $request->user();

        // Check if already verified
        if ($user->hasVerifiedEmail()) {
            return $this->success(
                ['user' => $user],
                'Email already verified'
            );
        }

        // Verify the email
        if ($user->markEmailAsVerified()) {
            event(new Verified($user));

            return $this->success(
                ['user' => $user],
                'Email verified successfully'
            );
        }

        return $this->fail(
            [],
            'Failed to verify email',
            422
        );
    }

    /**
     * Verify email from link (for users on another device)
     * This method validates the signature and verifies the user's email
     * without requiring them to be authenticated
     */
    public function verifyFromLink(Request $request, $id, $hash): RedirectResponse
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
        if (str_contains($frontendUrl, ',')) {
            $frontendUrl = explode(',', $frontendUrl)[0];
        }

        Log::info("Email verification attempt for user ID: $id, hash: $hash");
        Log::info("Full URL: " . $request->fullUrl());
        Log::info("Request method: " . $request->method());
        Log::info("All request data: ", $request->all());

        try {
            // Validate the signed URL — without this, the sha1(email) hash
            // below is the only gate, and it's guessable from a known
            // email/ID with no secret involved. Must check in absolute
            // mode: the default VerifyEmail notification generates the
            // link via temporarySignedRoute() with no $absolute argument,
            // which defaults to true, so the signature was computed over
            // the full absolute URL.
            if (!URL::hasValidSignature($request, true)) {
                Log::warning("Invalid email verification signature for user ID: $id");
                return redirect(rtrim($frontendUrl, '/') . '/email-verification-failed?reason=invalid');
            }

            $user = User::find($id);

            if (!$user) {
                Log::warning("User not found for email verification: $id");
                return redirect(rtrim($frontendUrl, '/') . '/email-verification-failed?reason=user_not_found');
            }

            // Verify the hash matches
            if (sha1($user->getEmailForVerification()) !== $hash) {
                Log::warning("Email verification hash mismatch for user: $id");
                return redirect(rtrim($frontendUrl, '/') . '/email-verification-failed?reason=invalid_hash');
            }

            // Check if already verified
            if ($user->hasVerifiedEmail()) {
                return redirect(rtrim($frontendUrl, '/') . '/email-verified?verified=1&already=true');
            }

            // Mark as verified
            $user->markEmailAsVerified();
            event(new Verified($user));

            Log::info("Email verified via link for user: {$user->id}");
            return redirect(rtrim($frontendUrl, '/') . '/email-verified?verified=1');
        } catch (\Exception $e) {
            Log::error("Email verification error: " . $e->getMessage());
            return redirect(rtrim($frontendUrl, '/') . '/email-verification-failed?reason=error');
        }
    }
}
