<?php

namespace App\Http\Middleware;

use App\HttpResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserType
{
    use HttpResponse;

    /**
     * Restrict a route to one or more user_type values, e.g.
     * Route::middleware('user_type:admin')->group(...).
     * Must run after auth:sanctum — assumes $request->user() is already resolved.
     */
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->user_type, $types, true)) {
            return $this->fail([], 'Forbidden — you do not have access to this resource.', 403);
        }

        return $next($request);
    }
}
