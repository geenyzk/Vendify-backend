<?php

namespace App\Http\Middleware;

use App\HttpResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    use HttpResponse;

    /**
     * Restrict a route to callers whose role carries the given permission
     * slug, e.g. Route::middleware('permission:settings')->group(...).
     * Must run after auth:sanctum (and typically after user_type:admin) —
     * assumes $request->user() is already resolved. Role::hasPermission()
     * was previously dead code; this is what actually puts it to use.
     */
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        $user = $request->user();

        if (!$user || !($user->role?->hasPermission($slug) ?? false)) {
            return $this->fail([], "Forbidden — this action requires the '{$slug}' permission.", 403);
        }

        return $next($request);
    }
}
