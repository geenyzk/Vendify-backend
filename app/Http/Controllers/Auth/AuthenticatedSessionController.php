<?php

namespace App\Http\Controllers\Auth;

use App\Classes\Payment\Payment;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\HttpResponse;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{

    use HttpResponse;

    private function authUserPayload(User $user, bool $includeDashboardData = false): User
    {
        $user->load('role.permissions');

        if (!$includeDashboardData) {
            $user->setAppends(['has_pin']);
        }

        return $user;
    }

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
            $token = $user->createToken($user->username)->plainTextToken;

            try {
                Payment::generateAccount($user);
            } catch (\Throwable $e) {
                Log::warning('Virtual account generation failed during login', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $user->loginStamp();
            } catch (\Throwable $e) {
                Log::warning('Login timestamp update failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $this->success([
                'user' => $this->authUserPayload($user),
                'token' => $token,
            ]);
        } catch (ValidationException $e) {
            error_log($e);
            return $this->fail($e->errors(), "Validation Error", 422);
        } catch (\Throwable $e) {
            Log::error('Login failed after credential validation', [
                'error' => $e->getMessage(),
            ]);

            return $this->fail([], 'Unable to sign in right now. Please try again.', 500);
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
        return $this->success([
            "user" => $this->authUserPayload(
                $request->user(),
                $request->boolean('include_dashboard')
            ),
        ]);
    }


    /**
     * Logout user
    * @group Authentication
     *
     */
    public function destroy(Request $request)
    {
        $accessToken = $request->user()?->currentAccessToken();
        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // For SPA clients, return a JSON success response instead of redirecting.
        return $this->success(null, 'Logged out');
    }
}
