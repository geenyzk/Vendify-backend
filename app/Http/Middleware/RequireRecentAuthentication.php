<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionSecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentAuthentication
{
    public function __construct(private readonly SessionSecurityService $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->currentSession($request);
        if (!$session || !$this->sessions->isRecentlyAuthenticated($session)) {
            return response()->json([
                'message' => 'Please confirm your password to continue.',
                'success' => false,
                'errors' => null,
                'type' => 'error',
                'code' => 'RECENT_AUTH_REQUIRED',
            ], 423);
        }

        return $next($request);
    }
}
