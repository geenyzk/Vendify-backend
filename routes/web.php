<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ServiceControlController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VTUServicesController;
use Illuminate\Support\Facades\Route;

Route::get("/", function(){
    return view("welcome");
});

Route::middleware(['auth:sanctum'])->group(function () {
    // Route::get("/user", [AuthenticatedSessionController::class, 'index']);
    Route::get("/logout", [AuthenticatedSessionController::class, 'destroy']);
    Route::post('/vtu/{service}', [VTUServicesController::class, 'handle']);
    Route::get('/vtu/{service}/plans', [VTUServicesController::class, 'plan']);
    Route::get('/vtu/{service}/verify', [VTUServicesController::class, 'verify']);
    Route::get('/transactions/report', [TransactionController::class, 'report']);


    Route::prefix("customer")->group(function(){

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

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';

