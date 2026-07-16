<?php

namespace App\Http\Controllers\Auth;

use App\Support\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\HttpResponse;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{

    use HttpResponse;

    private function authUserPayload(User $user, bool $includeDashboardData = false): User
    {
        $user->load('role.permissions');

        // Avoid the legacy query-backed default appends on session requests.
        $user->setAppends($includeDashboardData
            ? ['has_pin', 'banks', 'stats', 'badges', 'joined_at']
            : ['has_pin']);
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
        $profiling = (bool) config('performance.login_profiling', false);
        $startedAt = hrtime(true);
        $queries = 0;
        $queryMilliseconds = 0.0;
        $marks = [];

        if ($profiling) {
            DB::listen(function ($query) use (&$queries, &$queryMilliseconds) {
                $queries++;
                $queryMilliseconds += $query->time;
            });
        }

        $mark = static function (string $name, int $from) use (&$marks): int {
            $now = hrtime(true);
            $marks[$name] = round(($now - $from) / 1_000_000, 2);
            return $now;
        };

        try {
            $step = hrtime(true);
            $request->authenticate();
            $step = $mark('authentication', $step);
            $user = Auth::user();
            $token = $user->createToken($user->username)->plainTextToken;
            $step = $mark('token_creation', $step);

            // Registration creates accounts and POST /account/virtual-accounts
            // retries them. Login must not wait on third-party gateways.

            try {
                $user->loginStamp();
            } catch (\Throwable $e) {
                Log::warning('Login timestamp update failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $step = $mark('login_stamp', $step);

            // Only staff sign-ins are audited. Customers log in constantly and
            // would bury the admin trail in noise; who accessed the control
            // panel is the part that actually matters for an audit.
            if ($user->user_type === 'admin' || ($user->role?->is_staff ?? false)) {
                AuditLogger::record(
                    'login',
                    subject: $user,
                    description: sprintf('%s signed in', $user->fullname ?? $user->email),
                    actor: $user,
                );
            }

            $payload = [
                'user' => $this->authUserPayload($user),
                'token' => $token,
            ];
            $mark('serialization_prep', $step);
            $response = $this->success($payload);

            if ($profiling) {
                $totalMilliseconds = round((hrtime(true) - $startedAt) / 1_000_000, 2);
                Log::debug('Login performance profile', [
                    'total_ms' => $totalMilliseconds,
                    'query_count' => $queries,
                    'query_ms' => round($queryMilliseconds, 2),
                    'segments_ms' => $marks,
                ]);
                $response->headers->set('Server-Timing', implode(', ', [
                    'app;dur=' . $totalMilliseconds,
                    'db;dur=' . round($queryMilliseconds, 2) . ';desc="' . $queries . ' queries"',
                    ...array_map(
                        fn ($name, $duration) => str_replace('_', '-', $name) . ';dur=' . $duration,
                        array_keys($marks),
                        array_values($marks),
                    ),
                ]));
            }

            return $response;
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
