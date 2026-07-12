<?php

namespace App\Services\AiManager\Tools;

use App\Models\ChildInstance;
use App\Models\SimDevice;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;

/**
 * Monitoring sweep across every moving part of the platform, with the
 * problems pre-diagnosed into an `issues` list so the assistant can lead
 * with "3 things need attention" instead of a wall of metrics.
 *
 * Deliberately DB-only — no live vendor HTTP pings — so it always returns in
 * milliseconds. Vendor connectivity is read from the stored `connection`
 * flag, which the existing health checks maintain.
 */
class GetSystemHealthTool extends AiTool
{
    private const STUCK_PENDING_MINUTES = 30;
    private const STALE_AFFILIATE_MINUTES = 15;
    private const FAILURE_RATE_ALERT = 0.20; // >20% of today's transactions failing

    public function name(): string
    {
        return 'get_system_health';
    }

    public function description(): string
    {
        return 'Run a monitoring sweep of the whole platform and report anything that needs attention: transactions stuck in pending, elevated failure rates in the last 24h, vendors flagged disconnected or below their auto-fund threshold, affiliate (child) instances that have gone quiet, and SIM devices offline. Returns a pre-diagnosed issues list plus the underlying metrics. Use for "is everything ok", "health check", or "any problems" questions.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function handle(array $arguments, User $actor): array
    {
        $issues = [];
        $metrics = ['as_of' => now()->toDateTimeString()];

        // --- Transactions -------------------------------------------------
        $stuckPending = Transaction::where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(self::STUCK_PENDING_MINUTES))
            ->count();
        $last24h = Transaction::where('created_at', '>=', now()->subDay())
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'fail' THEN 1 ELSE 0 END) as failed")
            ->first();
        $total24h = (int) ($last24h->total ?? 0);
        $failed24h = (int) ($last24h->failed ?? 0);
        $failureRate = $total24h > 0 ? round($failed24h / $total24h, 3) : 0.0;

        $metrics['transactions'] = [
            'stuck_pending' => $stuckPending,
            'last_24h_total' => $total24h,
            'last_24h_failed' => $failed24h,
            'failure_rate_24h' => $failureRate,
        ];
        if ($stuckPending > 0) {
            $issues[] = "{$stuckPending} transaction(s) stuck in pending for over " . self::STUCK_PENDING_MINUTES . ' minutes — they may need a requery or manual status update.';
        }
        if ($total24h >= 5 && $failureRate >= self::FAILURE_RATE_ALERT) {
            $issues[] = 'High failure rate in the last 24h: ' . round($failureRate * 100) . "% ({$failed24h}/{$total24h}) — check vendor connections and balances.";
        }

        // --- Vendors --------------------------------------------------------
        $vendorRows = [];
        foreach (Vendor::where('active', true)->get() as $vendor) {
            $balance = (float) str_replace(',', '', (string) ($vendor->balance ?? '0'));
            $threshold = $vendor->auto_fund_threshold !== null ? (float) $vendor->auto_fund_threshold : null;
            $belowThreshold = $threshold !== null && $balance < $threshold;
            $vendorRows[] = [
                'name' => $vendor->name,
                'balance' => $balance,
                'connected' => (bool) $vendor->connection,
                'below_auto_fund_threshold' => $belowThreshold,
            ];
            if (!$vendor->connection) {
                $issues[] = "Vendor \"{$vendor->name}\" is flagged as disconnected — purchases routed to it will fail.";
            }
            if ($belowThreshold) {
                $issues[] = "Vendor \"{$vendor->name}\" balance (" . number_format($balance, 2) . ") is below its auto-fund threshold (" . number_format((float) $threshold, 2) . ').';
            }
        }
        $metrics['vendors'] = $vendorRows;

        // --- Affiliates (child instances) ---------------------------------
        try {
            $staleAffiliates = ChildInstance::where('status', 'active')
                ->where('last_seen_at', '<', now()->subMinutes(self::STALE_AFFILIATE_MINUTES))
                ->count();
            $metrics['affiliates'] = [
                'active' => ChildInstance::where('status', 'active')->count(),
                'stale' => $staleAffiliates,
            ];
            if ($staleAffiliates > 0) {
                $issues[] = "{$staleAffiliates} active affiliate site(s) haven't checked in for over " . self::STALE_AFFILIATE_MINUTES . ' minutes.';
            }
        } catch (\Throwable) {
            // Affiliate tables absent on standalone installs — skip quietly.
        }

        // --- SIM vending devices -------------------------------------------
        try {
            $totalDevices = SimDevice::count();
            if ($totalDevices > 0) {
                $onlineDevices = SimDevice::online()->count();
                $metrics['sim_devices'] = ['total' => $totalDevices, 'online' => $onlineDevices];
                if ($onlineDevices === 0) {
                    $issues[] = 'All SIM vending devices are offline — own-SIM fulfilment is down.';
                } elseif ($onlineDevices < $totalDevices) {
                    $issues[] = ($totalDevices - $onlineDevices) . ' of ' . $totalDevices . ' SIM vending device(s) are offline.';
                }
            }
        } catch (\Throwable) {
            // SIM vending tables absent — skip quietly.
        }

        // --- Presence -------------------------------------------------------
        try {
            $metrics['online_users'] = User::where('last_seen_at', '>=', now()->subMinutes(5))->count();
        } catch (\Throwable) {
            // Column absent pre-migration — skip quietly.
        }

        return [
            'status' => $issues === [] ? 'healthy' : 'issues_found',
            'issues' => $issues,
            'metrics' => $metrics,
        ];
    }
}
