<?php

namespace App\Services\AiManager;

use App\Classes\AdminNotifier;
use App\Models\AiAlert;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AiManager\Tools\GetSystemHealthTool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One shared monitoring sweep, used from two places: the AiMonitor middleware
 * (piggybacks on staff traffic) and the ai:sweep scheduled command (runs
 * every 5 minutes even when nobody is browsing). It combines the live
 * threshold checks (GetSystemHealthTool) with trend anomalies computed from
 * daily baselines, upserts the findings as AiAlert rows, and pushes newly
 * detected criticals through AdminNotifier (email + in-app bell).
 */
class HealthSweeper
{
    private const SWEEP_INTERVAL_SECONDS = 120;
    private const SNOOZE_MINUTES = 60;
    private const BASELINE_WEEKS = 4;       // compare against up to 4 same-weekdays
    private const BASELINE_MIN_ROWS = 3;    // don't judge trends on thin history
    private const VOLUME_DROP_RATIO = 0.5;  // yesterday under 50% of typical
    private const VOLUME_SPIKE_RATIO = 2.0; // yesterday over 200% of typical
    private const BACKFILL_DAYS = 21;       // seed enough history for baselines

    /**
     * Rate-limited entry point shared by the middleware and the scheduled
     * command — whichever fires first within the interval does the work and
     * the other skips, so the two paths never double-sweep.
     *
     * @return array{issues: int, new_alerts: int}|null null when throttled
     */
    public function sweepIfDue(User $actor): ?array
    {
        if (!Cache::add('ai-monitor:sweep-lock', 1, self::SWEEP_INTERVAL_SECONDS)) {
            return null;
        }

        return $this->sweep($actor);
    }

    /** @return array{issues: int, new_alerts: int} */
    public function sweep(User $actor): array
    {
        $issues = (new GetSystemHealthTool())->handle([], $actor)['issues'] ?? [];

        $this->recordDailyStats();
        $issues = array_merge($issues, $this->trendIssues());

        $fingerprints = [];
        $newAlerts = 0;
        foreach ($issues as $issue) {
            // Numbers change run to run ("3 stuck" -> "5 stuck"); normalize
            // them out so the same underlying condition updates one alert.
            $fingerprint = sha1(preg_replace('/\d+(\.\d+)?/', '#', $issue));
            $fingerprints[] = $fingerprint;

            $existing = AiAlert::unacknowledged()->where('fingerprint', $fingerprint)->first();
            if ($existing) {
                // Refresh the wording (counts move) and bump updated_at so
                // the UI can show how recently it was still true.
                $existing->update(['title' => $issue]);
                continue;
            }

            // Dismissing a persistent condition snoozes it for an hour —
            // otherwise every acknowledgement would resurrect minutes later.
            $snoozed = AiAlert::where('fingerprint', $fingerprint)
                ->where('acknowledged_at', '>', now()->subMinutes(self::SNOOZE_MINUTES))
                ->exists();
            if ($snoozed) {
                continue;
            }

            $alert = AiAlert::create([
                'severity' => $this->severity($issue),
                'title' => $issue,
                'fingerprint' => $fingerprint,
            ]);
            $newAlerts++;

            if ($alert->severity === 'critical') {
                AdminNotifier::notifyAiAlert($alert);
            }
        }

        // Conditions that cleared on their own resolve their alerts, so the
        // floating button only ever shows problems that are still true.
        AiAlert::unacknowledged()
            ->whereNotIn('fingerprint', $fingerprints ?: ['-'])
            ->update(['acknowledged_at' => now()]);

        return ['issues' => count($issues), 'new_alerts' => $newAlerts];
    }

    private function severity(string $issue): string
    {
        $lowered = strtolower($issue);
        foreach (['disconnected', 'down', 'all sim', 'offline'] as $word) {
            if (str_contains($lowered, $word)) {
                return 'critical';
            }
        }
        return 'warning';
    }

    // ------------------------------------------------------------------
    // Daily baselines + trend anomalies
    // ------------------------------------------------------------------

    /**
     * One ai_daily_stats row per completed day. Backfills missing days (a
     * few cheap aggregate queries the first time, then just yesterday once
     * per day) so weekday baselines accumulate on their own.
     */
    private function recordDailyStats(): void
    {
        $known = DB::table('ai_daily_stats')->pluck('stat_date')->all();
        $known = array_flip(array_map(fn ($d) => (string) $d, $known));

        for ($ago = 1; $ago <= self::BACKFILL_DAYS; $ago++) {
            $day = Carbon::today()->subDays($ago);
            if (isset($known[$day->toDateString()])) {
                continue;
            }

            $row = Transaction::whereBetween('created_at', [$day, $day->copy()->endOfDay()])
                ->selectRaw("COUNT(*) as total, COALESCE(SUM(amount), 0) as volume, SUM(CASE WHEN status = 'fail' THEN 1 ELSE 0 END) as failed")
                ->first();

            DB::table('ai_daily_stats')->insert([
                'stat_date' => $day->toDateString(),
                'tx_count' => (int) ($row->total ?? 0),
                'tx_volume' => (float) ($row->volume ?? 0),
                'failed_count' => (int) ($row->failed ?? 0),
                'signups' => User::whereBetween('created_at', [$day, $day->copy()->endOfDay()])->count(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Compare yesterday against the average of the same weekday over the
     * previous weeks — catches "sales are half of a normal Saturday", which
     * no fixed threshold can see.
     *
     * @return list<string>
     */
    private function trendIssues(): array
    {
        $yesterday = Carbon::yesterday();
        $current = DB::table('ai_daily_stats')->where('stat_date', $yesterday->toDateString())->first();
        if (!$current) {
            return [];
        }

        $baseline = DB::table('ai_daily_stats')
            ->where('stat_date', '<', $yesterday->toDateString())
            ->whereRaw('DAYOFWEEK(stat_date) = ?', [$yesterday->dayOfWeekIso % 7 + 1])
            ->orderByDesc('stat_date')
            ->limit(self::BASELINE_WEEKS)
            ->get();

        if ($baseline->count() < self::BASELINE_MIN_ROWS) {
            return []; // not enough history to call anything abnormal
        }

        $issues = [];
        $weekday = $yesterday->format('l');
        $avgVolume = (float) $baseline->avg('tx_volume');
        $volume = (float) $current->tx_volume;

        if ($avgVolume > 0 && $volume < $avgVolume * self::VOLUME_DROP_RATIO) {
            $drop = round((1 - $volume / $avgVolume) * 100);
            $issues[] = "Yesterday's transaction volume (₦" . number_format($volume, 2) . ") was {$drop}% below a typical {$weekday} (₦" . number_format($avgVolume, 2) . ' average) — check vendors, payments, and app availability.';
        } elseif ($avgVolume > 0 && $volume > $avgVolume * self::VOLUME_SPIKE_RATIO) {
            $spike = round(($volume / $avgVolume - 1) * 100);
            $issues[] = "Yesterday's transaction volume (₦" . number_format($volume, 2) . ") was {$spike}% above a typical {$weekday} — worth confirming it's genuine demand and vendor balances can keep up.";
        }

        $avgFailRate = (float) $baseline->avg(fn ($r) => $r->tx_count > 0 ? $r->failed_count / $r->tx_count : 0);
        $failRate = $current->tx_count > 0 ? $current->failed_count / $current->tx_count : 0;
        if ($current->tx_count >= 10 && $failRate > max(0.1, $avgFailRate * 2)) {
            $issues[] = "Yesterday's failure rate (" . round($failRate * 100) . '%) was well above the typical ' . round($avgFailRate * 100) . "% for a {$weekday}.";
        }

        return $issues;
    }
}
