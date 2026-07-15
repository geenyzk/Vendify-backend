<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check all vendor balances every 30 minutes and auto-fund those below threshold.
// Run `php artisan vendors:check-balances --dry-run` to test without dispatching jobs.
Schedule::command('vendors:check-balances')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('vendors:check-balances scheduled run failed.');
    });

// Prunes old success/fail transactions per Settings > Transaction — the
// command itself checks the enabled toggle and no-ops if it's off, so this
// runs daily regardless but only deletes anything when an admin opted in.
Schedule::command('transactions:prune')
    ->daily()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('transactions:prune scheduled run failed.');
    });

// Ages out the audit trail per config/audit.php (AUDIT_RETENTION_DAYS). The
// log is append-only and can't be deleted from the UI, so this is the only
// thing that trims the table.
Schedule::command('audit:prune')
    ->daily()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('audit:prune scheduled run failed.');
    });

// Refreshes local DataPlan rows from each vendor's live catalogue (e.g.
// Ogdams) so a purchase never has to call the vendor's API to resolve a
// plan ID — no-ops for vendor classes that don't implement syncPlans().
Schedule::command('vendors:sync-plans')
    ->daily()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('vendors:sync-plans scheduled run failed.');
    });

// Refunds SIM vend jobs whose device lease or pending TTL lapsed without an
// ack — the customer-facing safety net for the SIM vending channel (see
// ExpireSimVendJobs for why expired leases are never re-dispatched).
Schedule::command('sim:expire-jobs')
    ->everyMinute()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('sim:expire-jobs scheduled run failed.');
    });

// Sends any admin broadcast scheduled for "later" once its time is due —
// see BroadcastController::send()'s sendNow=false path.
Schedule::command('broadcasts:send-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('broadcasts:send-scheduled scheduled run failed.');
    });

// AI monitor sweep (see HealthSweeper): records platform problems as
// AiAlert rows and emails critical ones — independent of admin traffic, so
// an overnight outage is noticed overnight. The AiMonitor middleware covers
// installs whose host has no cron; its cache lock keeps the two from
// double-sweeping.
Schedule::command('ai:sweep')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('ai:sweep scheduled run failed.');
    });

