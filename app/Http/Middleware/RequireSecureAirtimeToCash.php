<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class RequireSecureAirtimeToCash
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isSecure()) {
            return response()->json(['success' => false, 'message' => 'HTTPS is required for SIM verification.'], 400);
        }

        return $next($request);
    }
}
