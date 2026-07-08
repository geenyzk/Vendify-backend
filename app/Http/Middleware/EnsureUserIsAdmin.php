<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Every /admin/* route previously relied on auth:sanctum alone (any logged-in
// user, not just admins) plus a handful of ad-hoc `user_type === 'admin'`
// checks scattered in individual controllers (e.g. SearchController). This
// centralizes that check so it can't be forgotten on a new route.
//
// Staff-ness is determined by the assigned role (role->is_staff), not
// user_type — user_type is reserved for pricing-tier lookups (DataPlan,
// CablePlan, etc.) and is no longer read for authorization anywhere.
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->role || !$user->role->is_staff) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
