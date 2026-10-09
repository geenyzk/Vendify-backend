<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class RequireSecureIdentitySubmission
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('production') && !$request->isSecure()) {
            return response()->json([
                'status' => 'failed',
                'error_code' => 'secure_connection_required',
                'message' => 'A secure connection is required for identity verification.',
                'retryable' => false,
            ], 400);
        }

        return $next($request);
    }
}
