<?php

namespace App\Http\Middleware;

use App\Services\AiManager\HealthSweeper;
use Closure;
use Illuminate\Http\Request;

/**
 * Passive monitoring: as staff use the admin panel, this piggybacks a
 * platform health sweep (see HealthSweeper) so alerts surface even on
 * installs whose host has no cron for the ai:sweep scheduled command.
 *
 * Cost control: only staff requests are considered at all, and the sweeper's
 * own cache lock limits real sweeps to one per interval platform-wide (both
 * for this middleware and the scheduled command combined). Failures never
 * break the request — monitoring is best-effort by design.
 */
class AiMonitor
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $middleware = $request->route()?->gatherMiddleware() ?? [];
        if (!in_array('auth:sanctum', $middleware, true)) {
            return $response;
        }

        // Health checks can aggregate weeks of data and contact notification
        // channels. Run them after the response is sent, never in an admin
        // navigation request's critical path.
        app()->terminating(function () use ($request) {
            try {
                $user = $request->user();
                if ($user && $user->role?->is_staff) {
                    app(HealthSweeper::class)->sweepIfDue($user);
                }
            } catch (\Throwable) {
                // Monitoring is best-effort.
            }
        });

        return $response;
    }
}
