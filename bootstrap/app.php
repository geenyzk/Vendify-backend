<?php

use App\Http\Middleware\AiMonitor;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\EnforceSecureSession;
use App\Http\Middleware\HandleRequest;
use App\Http\Middleware\ProfilePerformance;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Http\Middleware\RejectImpersonatedSession;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\TrackLastSeen;
use App\Http\Middleware\VerifyChildSignature;
use App\Http\Middleware\VerifySimDeviceSignature;
use App\Support\ErrorMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        api: __DIR__.'/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->trustProxies(at: '*');

        $middleware->append([
            ProfilePerformance::class,
            HandleRequest::class
        ]);

        // Presence heartbeat for the admin "online now" stat — throttled
        // to one users.last_seen_at write per user per minute.
        // AiMonitor piggybacks a platform health sweep on staff traffic
        // (throttled to one sweep per 2 minutes platform-wide) and records
        // problems as AiAlert rows for the admin UI's floating AI button.
        $middleware->api(append: [TrackLastSeen::class, AiMonitor::class]);

        $middleware->alias([
            'user_type' => EnsureUserType::class,
            'staff' => EnsureUserIsAdmin::class,
            'permission' => EnsurePermission::class,
            'not.impersonating' => RejectImpersonatedSession::class,
            'secure.session' => EnforceSecureSession::class,
            'recent.auth' => RequireRecentAuthentication::class,
            'verify.child.hmac' => VerifyChildSignature::class,
            'verify.sim.hmac' => VerifySimDeviceSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Safety net: an API client must never receive raw SQL/PDO internals
        // (schema names, the failing query, SQLSTATE codes). Log the real
        // exception for debugging and return the standard envelope with a
        // message a person can act on. Validation/auth/404/429 keep Laravel's
        // own handling — only database faults are rewritten here.
        $exceptions->render(function (QueryException $e, Request $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) {
                return null;
            }

            Log::error('Database error on API request', [
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => ErrorMessage::humanize($e),
                'success' => false,
                'errors' => null,
                'type' => 'error',
            ], ErrorMessage::statusFor($e));
        });
    })->create();
