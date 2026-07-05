<?php

namespace App\Http\Controllers\Auth;

use App\Classes\Payment\Payment;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

            $request->validate([
                'fullname' => ['string', 'max:255'],
                'username' => ['required', 'string', 'max:255', 'unique:'.User::class],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
                'phone' => ['required', 'string', 'max:255', 'unique:'.User::class],
                'pin' => ['required', 'digits:4'],
                'referral_code' => ['string', 'nullable', 'max:255', 'exists:users,referral_code'],
                'password' => ['required', Rules\Password::defaults()],
            ]);

            $user = User::create([
                'fullname' => $request->fullname ?? 'Anonymous',
                'username' => $request->username,
                'phone' => $request->phone,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            Auth::login($user);
            // $token = $user->createToken($user->username);
            Payment::generateAccount($user);
            $user->loginStamp();
            $user->assignRole('user');

            // Send email verification notification
            event(new Registered($user));
            $user->sendEmailVerificationNotification();

            // Return user data with unverified email status
            return $this->success(
                [
                    'user' => $user,
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
