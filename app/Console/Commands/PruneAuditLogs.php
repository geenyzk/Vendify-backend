<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Retention for the append-only audit trail. Entries can't be deleted from the
 * UI, so ageing them out on a schedule is the only way the table is trimmed.
 */
class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune {--days= : Delete entries older than this many days}';

    protected $description = 'Delete audit log entries older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('audit.retention_days', 180));

        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // Chunked so a long-neglected table can't exhaust memory or hold one
        // enormous lock.
        $total = 0;
        do {
            $deleted = AuditLog::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Pruned {$total} audit log entr" . ($total === 1 ? 'y' : 'ies') . " older than {$days} days.");

        return self::SUCCESS;
    }
}
