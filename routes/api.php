<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ServiceControlController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VTUServicesController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

Route::post("/login", [AuthenticatedSessionController::class, 'store']);
Route::post("/register", [RegisteredUserController::class, 'store']);
Route::any("/webhook/{type}/{identifier}", [WebhookController::class, 'handle']);

Route::post('/insert', [AdminController::class, 'universalInsert']);
Route::get('/table/{table}', [AdminController::class, 'universalGet']);

// Update a record by ID in a table universalBulkCreateOrUpdate
Route::get('/table/{table}/{id}', [AdminController::class, 'universalShow']);
Route::match(["post", 'put'], '/table/{table}/{id}', [AdminController::class, 'universalCreateOrUpdate']);
Route::match(["post", 'put'], '/table/{table}', [AdminController::class, 'universalBulkCreateOrUpdate']);
Route::delete('/table/{table}/{id}', [AdminController::class, 'universalDelete']);


Route::get('/test-mail', function () {
    Mail::raw('Test from Laravel', function ($message) {
        $message->to('officialspurconnect@gmail.com')
            ->subject('Test Subject');
    });

    return 'Sent!';
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::get("/user", [AuthenticatedSessionController::class, 'index']);
    Route::get("/logout", [AuthenticatedSessionController::class, 'destroy']);
    Route::post('/vtu/{service}', [VTUServicesController::class, 'handle']);
    Route::get('/vtu/{service}/plans', [VTUServicesController::class, 'plan']);
    Route::get('/vtu/{service}/verify', [VTUServicesController::class, 'verify']);
    Route::get('/transactions/report', [TransactionController::class, 'report']);

    Route::prefix("customer")->group(function () {
        Route::post('/{id}/convert-referral', [CustomerController::class, 'convertReferralToWallet']);
        Route::post('/account/upgrade', [CustomerController::class, 'upgrade']);
    });

    Route::prefix("admin")->group(function () {
        Route::resource('users', UserController::class)
            ->withoutMiddleware('auth:sanctum')
            ->only(['store']);

        Route::resource('users', UserController::class)
            ->only(['show', 'update', 'destroy', 'index']);

        Route::resource('controls', ServiceControlController::class);
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::post('/broadcast', [AdminController::class, 'broadcast']);
        Route::get('/vendor/{id}/refresh-token', [AdminController::class, 'refreshToken']);

        Route::post("/users/{id}/fund", [AdminController::class, 'fundUser']);

        Route::get("/airtime_discount", [AdminController::class, 'airtimeDiscount']);
    });

    Route::get("/system-information-get", [AdminController::class, 'systemInformation']);
});

Route::prefix('vtu')->group(function () {
    Route::get('/user', [VTUServicesController::class, 'getUser']);
    Route::get('/networks', [VTUServicesController::class, 'getNetworks']);
    Route::get('/network/plans', [VTUServicesController::class, 'getNetworkPlans']);
    Route::get('/data', [VTUServicesController::class, 'getDataPlans']);
    Route::get('/data/{id}', [VTUServicesController::class, 'getDataPlanById']);

    Route::get('/validate/iuc', [VTUServicesController::class, 'validateIUC']);
    Route::get('/validate/meter', [VTUServicesController::class, 'validateMeter']);

    Route::post('/airtime/funding', [VTUServicesController::class, 'airtimeFunding']);
    Route::post('/airtime/topup', [VTUServicesController::class, 'airtimeTopup']);
    Route::post('/data/purchase', [VTUServicesController::class, 'dataPurchase']);
    Route::post('/cable', [VTUServicesController::class, 'cableSubscription']);
    Route::post('/electricity', [VTUServicesController::class, 'electricityPayment']);
});
