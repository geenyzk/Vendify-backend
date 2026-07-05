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

