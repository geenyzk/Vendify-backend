<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Generic counterpart to EnsureUserIsAdmin (alias `admin`), for routes that
// need `user_type:xxx` with a parameter instead of a hardcoded check.
class CheckUserType
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->user_type, $types, true)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
