<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionSecurityService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnforceSecureSession
{
    public function __construct(private readonly SessionSecurityService $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Some narrowly-scoped tests intentionally build only the tables they
        // exercise. Production fails closed until the security migration is
        // present rather than silently running without session enforcement.
        if (!Schema::hasTable('auth_sessions')) {
            if (app()->environment('testing')) {
                return $next($request);
            }
            return response()->json([
                'message' => 'Session security is not ready. Run the pending database migrations.',
                'success' => false,
                'code' => 'SESSION_SECURITY_NOT_READY',
            ], 503);
        }

        try {
            $session = $this->sessions->ensureActive($request);
            $request->attributes->set('auth_session', $session);
        } catch (AuthenticationException $exception) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'success' => false,
                'errors' => null,
                'type' => 'error',
                'code' => 'SESSION_EXPIRED',
            ], 401);
        }

        $response = $next($request);
        $expiry = in_array($session->channel, ['web', 'impersonation'], true) ? $session->idle_expires_at : $session->absolute_expires_at;
        if ($expiry) {
            $response->headers->set('X-Session-Expires-At', $expiry->toIso8601String());
        }
        $response->headers->set('X-Auth-Session-Id', $session->id);

        return $response;
    }
}
