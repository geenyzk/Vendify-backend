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
 * Resolved via the sanctum guard directly (not $request->user()) because
 * this runs as group middleware, before the route's auth middleware.
 */
class TrackLastSeen
{
    private const TOUCH_INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next)
    {
        try {
            $user = $request->user('sanctum');

            if ($user && (
                $user->last_seen_at === null
                || $user->last_seen_at->lt(now()->subSeconds(self::TOUCH_INTERVAL_SECONDS))
            )) {
                // Quiet raw update: no model events, no updated_at churn —
                // this is telemetry, not a data change.
                DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now()]);
            }
        } catch (\Throwable $e) {
            // Presence is best-effort; a missing column (fresh install
            // pre-migration, sqlite test tables) must never break a request.
        }

        return $next($request);
    }
}
