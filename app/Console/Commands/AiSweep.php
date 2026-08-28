<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AiManager\HealthSweeper;
use Illuminate\Console\Command;

/**
 * Scheduled counterpart of the AiMonitor middleware: keeps the AI monitor
 * sweeping (and emailing critical alerts) even when no admin is browsing —
 * a 2am vendor outage shouldn't wait for the morning login to be noticed.
 * Scheduled every five minutes in routes/console.php.
 */
class AiSweep extends Command
{
    protected $signature = 'ai:sweep';

    protected $description = 'Run the AI monitor health sweep and record/notify alerts';

    public function handle(HealthSweeper $sweeper): int
    {
        // The health tool's signature takes the acting user (unused by the
        // read-only sweep itself) — any admin account stands in for "the system".
        $actor = User::whereHas('role', fn ($role) => $role->where('is_staff', true)->where('is_active', true))->first() ?? User::first();
        if (!$actor) {
            $this->warn('No users exist yet — nothing to monitor.');
            return self::SUCCESS;
        }

        $result = $sweeper->sweepIfDue($actor);
        if ($result === null) {
            $this->info('Skipped — a sweep already ran within the last interval.');
            return self::SUCCESS;
        }

        $this->info("Sweep complete: {$result['issues']} open issue(s), {$result['new_alerts']} new alert(s).");

        return self::SUCCESS;
    }
}
