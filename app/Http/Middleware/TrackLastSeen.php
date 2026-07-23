<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Presence heartbeat: stamps users.last_seen_at on authenticated API
 * traffic so the admin dashboard can report "online now" (seen within the
 * last few minutes), not just registered totals.
 *
 * Write-throttled to once per minute per user — dashboards poll and SPAs
 * chatter, and a per-request UPDATE would dwarf the value of the metric.
 * The write is deferred until application termination, after the response
 * has been sent, and public routes never resolve the authentication guard.
 */
class TrackLastSeen
{
    private const TOUCH_INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (!$this->routeRequiresAuthentication($request)) {
            return $response;
        }

        // Presence telemetry must not delay the response. At this point the
        // route's auth middleware has already resolved the user, so public
        // auth/catalog requests also avoid needlessly invoking Sanctum.
        app()->terminating(function () use ($request) {
            try {
                $user = $request->user();

                if ($user && (
                    $user->last_seen_at === null
                    || $user->last_seen_at->lt(now()->subSeconds(self::TOUCH_INTERVAL_SECONDS))
                )) {
                    DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now()]);
                }
            } catch (\Throwable) {
                // Best-effort telemetry.
            }
        });

        return $response;
    }

    private function routeRequiresAuthentication(Request $request): bool
    {
        $middleware = $request->route()?->gatherMiddleware() ?? [];

        return in_array('auth:sanctum', $middleware, true);
    }
}
