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

        try {
            $user = $request->user('sanctum');
            if ($user && $user->role?->is_staff) {
                app(HealthSweeper::class)->sweepIfDue($user);
            }
        } catch (\Throwable) {
            // Missing tables pre-migration, cache hiccups — never the
            // request's problem.
        }

        return $response;
    }
}
