<?php

namespace App\Http\Controllers\Auth;

use App\Classes\Payment\Payment;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\HttpResponse;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{

    use HttpResponse;

    /**
     * Login a user
     *
     * @group Authentication
     *
     * This endpoint logs in a user and returns an API token.
     *
     * @bodyParam email string required The user's email. Example: user@example.com
     * @bodyParam password string required The user's password. Example: password123
     *
     * @response 200 {
     *   "status": true,
     *   "message": "Request successful",
     *   "data": {
     *     "user": {
     *       "id": 1,
     *       "username": "john_doe",
     *       "email": "user@example.com"
     *     },
     *     "token": "your-generated-token"
     *   }
     * }
     */
    public function store(LoginRequest $request)
    {

        try {
            $request->authenticate();
            $user = Auth::user();
            // $token = $user->createToken($user->username)->plainTextToken;
            Payment::generateAccount($user);
            $user->loginStamp();
            $user->role = $user->user_type;
            return $this->redirect($user->user_type !== "admin" ?"/customer" :'/admin');
        } catch (ValidationException $e) {
            error_log($e);
            return $this->fail($e->errors(), "Validation Error", 422);
        }catch (\Exception $e){
                Log::info($e);
        }
    }

    /**
     * Get the current authenticated user
     *
     * @group Authentication
     * @unauthenticated
     *
     * This endpoint returns the currently authenticated user's details.
     *
     * @response 200 {
     *   "status": true,
     *   "message": "Request successful",
     *   "data": {
     *     "user": {
     *       "id": 1,
     *       "username": "john_doe",
     *       "email": "user@example.com"
     *     }
     *   }
     * }
     */
    public function index(Request $request)
    {
        return $this->success(["user" => $request->user()->load('role')]);
    }


    /**
     * Logout user
    * @group Authentication
     *
     */
    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // For SPA clients, return a JSON success response instead of redirecting.
        return $this->success(null, 'Logged out');
    }
}

