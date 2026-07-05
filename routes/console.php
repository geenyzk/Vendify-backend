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

