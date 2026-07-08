<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get("/", function(){
    return view("welcome");
});

Route::get('/cache', function () {
    return cache()->flush();
});

// Deploy helper for shared hosting with no SSH/artisan access — hit after
// each deploy to run pending migrations. Token-gated: it refuses to run at
// all if DEPLOY_SECRET is missing and rejects anything that does not match.
Route::get('/deploy/{action}', function (string $action) {
    $secret = env('DEPLOY_SECRET');
    if (!$secret || !hash_equals($secret, (string) request('token'))) {
        abort(403, 'Invalid or missing deploy token.');
    }

    $allowedActions = ['migrate', 'refresh', 'fresh'];
    if (!in_array($action, $allowedActions, true)) {
        abort(404, 'Unsupported deploy action.');
    }

    $command = match ($action) {
        'migrate' => ['migrate', ['--force' => true]],
        'refresh' => ['migrate:refresh', ['--force' => true]],
        'fresh' => ['migrate:fresh', ['--force' => true]],
    };

    Artisan::call($command[0], $command[1]);
    $output = Artisan::output();

    if ($action === 'migrate') {
        Artisan::call('db:seed', [
            '--class' => RolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);
        $output .= Artisan::output();
    }

    return response()->json([
        'success' => true,
        'action' => $action,
        'output' => $output,
    ]);
})->where('action', '(migrate|refresh|fresh)');

