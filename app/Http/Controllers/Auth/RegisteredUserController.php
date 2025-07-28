<?php

namespace App\Http\Controllers\Auth;

use App\Class\Payment\Payment;
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
        try {
            $request->validate([
                'fullname' => ['required', 'string', 'max:255'],
                'username' => ['required', 'string', 'max:255', 'unique:'.User::class],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
                'phone' => ['required', 'string', 'max:255', 'unique:'.User::class],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ]);

            $user = User::create([
                'fullname' => $request->fullname,
                'username' => $request->username,
                'phone' => $request->phone,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            Auth::login($user);
            $token = $user->createToken($user->username);
            Payment::generateAccount($user);
            return $this->success(["user" =>$user, 'token' => $token->plainTextToken]);

        } catch (ValidationException $e) {
            return $this->fail( $e->errors(), "Validation Error", 422);

        }
    }

}
