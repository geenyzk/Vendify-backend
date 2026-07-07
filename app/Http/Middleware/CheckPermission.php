<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Gates the `permission:xxx` route groups nested under /admin — the outer
// `user_type:admin` group already confirms the user is staff-eligible; this
// checks the specific permission granted to their assigned role.
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user || !$user->role || !$user->role->hasPermission($permission)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
