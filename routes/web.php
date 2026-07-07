<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('scribe.index');
});

Route::get('/docs.postman', function () {
    abort_unless(Storage::disk('local')->exists('scribe/collection.json'), 404);

    return Response::download(storage_path('app/scribe/collection.json'));
})->name('scribe.postman');

Route::get('/docs.openapi', function () {
    abort_unless(Storage::disk('local')->exists('scribe/openapi.yaml'), 404);

    return Response::file(storage_path('app/scribe/openapi.yaml'), [
        'Content-Type' => 'application/yaml',
    ]);
})->name('scribe.openapi');

Route::get('/cache', function () {
    return Cache::flush();
});

// Deploy helper for shared hosting with no SSH/artisan access — hit after
// each deploy to run pending migrations. Token-gated: refuses to run at
// all if DEPLOY_SECRET isn't set (so this can never be accidentally left
// open), and rejects anything that doesn't match it via a timing-safe
// comparison.
Route::get('/deploy/migrate', function () {
    $secret = env('DEPLOY_SECRET');
    if (!$secret || !hash_equals($secret, (string) request('token'))) {
        abort(403, 'Invalid or missing deploy token.');
    }

    Artisan::call('migrate', ['--force' => true]);
    $output = Artisan::output();

    Artisan::call('db:seed', [
        '--class' => RolesAndPermissionsSeeder::class,
        '--force' => true,
    ]);
    $output .= Artisan::output();

    return response()->json([
        'success' => true,
        'output' => $output,
    ]);
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
