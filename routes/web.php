<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ServiceControlController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VTUServicesController;
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

// Route::middleware(['auth:sanctum'])->group(function () {
//     // Route::get("/user", [AuthenticatedSessionController::class, 'index']);
//     // SPA clients should POST to /logout, but we accept GET too for browser hits.
//     Route::match(['get', 'post'], "/logout", [AuthenticatedSessionController::class, 'destroy']);
//     Route::post('/vtu/{service}', [VTUServicesController::class, 'handle']);
//     Route::get('/vtu/{service}/plans', [VTUServicesController::class, 'plan']);
//     Route::get('/vtu/{service}/verify', [VTUServicesController::class, 'verify']);
//     Route::get('/transactions/report', [TransactionController::class, 'report']);

//     Route::prefix("customer")->group(function () {
//         Route::post('/{id}/convert-referral', [CustomerController::class, 'convertReferralToWallet']);
//         Route::post('/account/upgrade', [CustomerController::class, 'upgrade']);
//     });

//     Route::prefix("admin")->group(function () {
//         Route::resource('users', UserController::class)
//             ->withoutMiddleware('auth:sanctum')
//             ->only(['store']);

//         Route::resource('users', UserController::class)
//             ->only(['show', 'update', 'destroy', 'index']);

//         Route::resource('controls', ServiceControlController::class);

//         Route::get('/stats', [AdminController::class, 'stats']);
//         Route::get('/vendor/{id}/refresh-token', [AdminController::class, 'refreshToken']);

//         Route::post("/users/{id}/fund", [AdminController::class, 'fundUser']);

//         Route::get("/airtime_discount", [AdminController::class, 'airtimeDiscount']);
//     });

//     Route::get("/system-information-get", [AdminController::class, 'systemInformation']);
// });

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';

