<?php

namespace App\Http\Controllers\Auth;

use App\Support\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\HttpResponse;
use App\Models\User;
use App\Services\Auth\SessionSecurityService;
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
        $user->setAppends(['has_pin']);
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
            $user = $request->authenticate();
            $step = $mark('authentication', $step);
            $sessionSecurity = app(SessionSecurityService::class);
            $isMobile = $request->input('client_type') === 'mobile'
                || $request->header('X-Client-Platform') === 'app';
            if ($isMobile) {
                $credentials = $sessionSecurity->createMobileCredentials($user, $request);
                if ($request->hasSession()) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
                $session = null;
            } else {
                $request->session()->regenerate();
                $session = $sessionSecurity->createWebSession($user, $request, $request->boolean('remember'));
                $credentials = [];
            }
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

            AuditLogger::record(
                'login',
                subject: $user,
                description: sprintf('%s signed in', $user->fullname ?? $user->email),
                actor: $user,
                context: ['channel' => $isMobile ? 'mobile' : 'web', 'auth_session_id' => $session?->id ?? $credentials['session']['id']],
            );

            $payload = [
                'user' => $this->authUserPayload($user),
                'session' => $session ? $sessionSecurity->payload($session, $session->id) : $credentials['session'],
                ...$credentials,
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
        $security = app(SessionSecurityService::class);
        $session = $security->currentSession($request);
        return $this->success([
            "user" => $this->authUserPayload(
                $request->user(),
                $request->boolean('include_dashboard')
            ),
            'session' => $session ? $security->payload($session, $session->id) : null,
        ]);
    }


    /**
     * Logout user
    * @group Authentication
     *
     */
    public function destroy(Request $request)
    {
        $security = app(SessionSecurityService::class);
        $session = $security->currentSession($request);
        if ($session) {
            $security->revoke($session, 'logout');
        }
        $accessToken = $request->user()?->currentAccessToken();
        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        AuditLogger::record(
            'logout',
            subject: $request->user(),
            actor: $request->user(),
            description: 'User signed out.',
            context: ['auth_session_id' => $session?->id],
        );

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // For SPA clients, return a JSON success response instead of redirecting.
        return $this->success(null, 'Logged out');
    }
}
