<?php

namespace App\Http\Controllers\Auth;

use App\Classes\AdminNotifier;
use App\Classes\EventService;
use App\Classes\Payment\Payment;
use App\Classes\TransactionService;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;


class RegisteredUserController extends Controller
{

/**
 * Register a new user
*
    * @group Authentication
     * @unauthenticated

    * This endpoint creates a new user and returns an auth token.
    *
    * @bodyParam fullname string required Full name of the user.
    * @bodyParam username string required Unique username.
    * @bodyParam email string required Must be a valid email.
    * @bodyParam phone string required Unique phone number.
    * @bodyParam password string required Password.
    * @bodyParam password_confirmation string required Confirm Password.
    *
    * @response 200 {
    *   "status": true,
    *   "message": "Request successful",
    *   "data": {
    *     "user": {
    *       "id": 1,
    *       "fullname": "John Doe",
    *       "username": "johndoe",
    *       "email": "john@example.com",
    *       ...
    *     },
    *     "token": "eyJ0eXAiOiJKV1QiLC..."
    *   }
    * }
 */

    public function store(Request $request)
    {
        // Log::info($request->all());
        try {
            $settings = Setting::first();

            if ($settings && !$settings->registrations_open) {
                return $this->fail([], 'New registrations are currently closed.', 403);
            }

            $request->validate([
                'fullname' => ['string', 'max:255'],
                'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->whereNull('deleted_at')],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
                'phone' => ['required', 'string', 'max:255', Rule::unique('users', 'phone')->whereNull('deleted_at')],
                'referral_code' => ['string', 'nullable', 'max:255', 'exists:users,referral_code'],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ]);

            $referrer = $request->referral_code
                ? User::where('referral_code', $request->referral_code)->first()
                : null;

            $user = User::create([
                'fullname' => $request->fullname ?? 'Anonymous',
                'username' => $request->username,
                'phone' => $request->phone,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'referred_by' => $referrer?->id,
                'status' => 'active',
            ]);

            // Referral-count Events (e.g. "refer 5 friends") key off the
            // referrer's referred-user count, which just changed.
            if ($referrer) {
                try {
                    EventService::checkAndAward($referrer);
                } catch (\Throwable $e) {
                    Log::warning('Referral event award check failed during registration', [
                        'user_id' => $user->id,
                        'referrer_id' => $referrer->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Auth::login($user);
            $token = $user->createToken($user->username)->plainTextToken;

            try {
                Payment::generateAccount($user);
            } catch (\Throwable $e) {
                Log::warning('Virtual account generation failed during registration', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $user->loginStamp();
            } catch (\Throwable $e) {
                Log::warning('Login timestamp update failed during registration', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $user->assignRole('user');
            } catch (\Throwable $e) {
                Log::warning('Role assignment failed during registration', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $bonus = floatval($settings?->signup_bonus_amount ?? 0);
            if ($bonus > 0) {
                try {
                    TransactionService::fundUser($user, $bonus, 'credit', 'Signup bonus');
                } catch (\Throwable $e) {
                    Log::warning('Signup bonus funding failed during registration', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                AdminNotifier::notifySignup($user);
            } catch (\Throwable $e) {
                Log::warning('Admin signup notification failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // A failed send (bad SMTP config, provider outage, etc.) must
            // never break registration itself — the account already exists
            // and is logged in at this point regardless of whether the
            // verification email goes out.
            try {
                event(new Registered($user));
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $e) {
                Log::warning('Failed to send email verification notification', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Return user data with unverified email status
            return $this->success(
                [
                    'user' => $user->fresh()->load('role.permissions'),
                    'token' => $token,
                    'message' => 'Registration successful! Please verify your email.',
                    'email_verified_at' => $user->email_verified_at,
                ],
                'Registration successful'
            );
        } catch (ValidationException $e) {
            return $this->fail( $e->errors(), "Validation Error", 422);

        }
    }

}
