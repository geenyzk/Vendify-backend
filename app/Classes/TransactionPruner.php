<?php

namespace App\Classes;

use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class TransactionPruner
{
    // Never prune pending transactions — an old but still-unresolved
    // transaction is exactly the kind of record that needs to stay around
    // for manual reconciliation, not be silently deleted by a retention job.
    protected const PRUNABLE_STATUSES = ['success', 'fail'];

    /**
     * How many transactions the next run would delete, without deleting
     * anything — lets the admin see the impact before opting in.
     */
    public static function previewCount(): int
    {
        $settings = Setting::first();
        $days = (int) ($settings?->prune_transactions_after_days ?? 365);

        return Transaction::where('created_at', '<', now()->subDays($days))
            ->whereIn('status', self::PRUNABLE_STATUSES)
            ->count();
    }

    /**
     * Delete success/fail transactions older than the configured retention
     * window. $force bypasses the enabled toggle — used by the admin
     * "Prune now" action, where clicking the button is itself the opt-in.
     * The scheduled daily run always goes through with $force = false.
     */
    public static function run(bool $force = false): int
    {
        $settings = Setting::first();

        if (!$force && !($settings?->prune_transactions_enabled ?? false)) {
            return 0;
        }

        $days = (int) ($settings?->prune_transactions_after_days ?? 365);
        $cutoff = now()->subDays($days);

        $query = Transaction::where('created_at', '<', $cutoff)
            ->whereIn('status', self::PRUNABLE_STATUSES);

        $count = $query->count();
        if ($count > 0) {
            $query->delete();
        }

        $settings?->update(['transactions_last_pruned_at' => now()]);

        Log::info("TransactionPruner: pruned {$count} transaction(s) older than {$days} day(s).");

        return $count;
    }
}
