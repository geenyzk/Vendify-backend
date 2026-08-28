<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionSecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectImpersonatedSession
{
    public function __construct(private readonly SessionSecurityService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->currentSession($request);
        if ($session?->channel === 'impersonation') {
            return response()->json([
                'message' => 'This sensitive action is unavailable while viewing a customer account.',
                'success' => false,
                'errors' => null,
                'type' => 'error',
                'code' => 'IMPERSONATION_RESTRICTED',
            ], 403);
        }

        return $next($request);
    }
}
