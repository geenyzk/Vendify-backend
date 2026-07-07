<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ChildDirectiveController;
use App\Http\Controllers\ChildRegistrationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ResetWebsiteController;
use App\Http\Controllers\SearchController;
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

// Pull/ack half of the parent<->child channel — the child polls these on
// its own cron cadence (see child_backend's ParentSyncPullDirectives).
// This is a client-polling RPC, not an unsolicited webhook, so it gets its
// own route group + middleware rather than sharing the {type}/{identifier}
// shape above.
Route::prefix('child')->middleware('verify.child.hmac')->group(function () {
    Route::get('/{slug}/directives', [ChildDirectiveController::class, 'index']);
    Route::post('/{slug}/directives/{id}/ack', [ChildDirectiveController::class, 'ack']);
});

// Not HMAC-protected — the child has no shared_secret yet at this point.
// Trust is bootstrapped by the one-time registration_code itself (see
// AdminController::generateChildRegistrationCode / ChildRegistrationController).
Route::post('/child/register', [ChildRegistrationController::class, 'register']);

Route::post('/insert', [AdminController::class, 'universalInsert']);

Route::get('/table/{table}', [AdminController::class, 'universalGet']);


// Update a record by ID in a table universalBulkCreateOrUpdate
Route::get('/table/{table}/{id}', [AdminController::class, 'universalShow']);
Route::match(["post", 'put'],'/table/{table}/{id}', [AdminController::class, 'universalCreateOrUpdate']);
Route::match(["post", 'put'],'/table/{table}', [AdminController::class, 'universalBulkCreateOrUpdate']);
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
    Route::put("/account/pin", [AccountController::class, 'updatePin']);
    Route::post('/vtu/{service}', [VTUServicesController::class, 'handle']);
    Route::get('/vtu/{service}/plans', [VTUServicesController::class, 'plan']);
    Route::get('/vtu/{service}/verify', [VTUServicesController::class, 'verify']);
    Route::get('/transactions/report', [TransactionController::class, 'report']);
    Route::get('/search', [SearchController::class, 'userSearch']);

    Route::prefix("customer")->group(function(){
        Route::post('/{id}/convert-referral', [CustomerController::class, 'convertReferralToWallet']);
        Route::post('/account/upgrade', [CustomerController::class, 'upgrade']);
    });

    Route::prefix("admin")->group(function (){
        Route::resource('users', UserController::class);
        Route::resource('controls', ServiceControlController::class);

        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/analytics', [AnalyticsController::class, 'index']);
        Route::get('/search', [SearchController::class, 'adminSearch']);
        Route::post('/broadcast', [AdminController::class, 'broadcast']);
        Route::get('/vendor/{id}/refresh-token', [AdminController::class, 'refreshToken']);

        Route::get('/child-instances/{id}/secret', [AdminController::class, 'childInstanceSecret']);
        Route::post('/child-instances/{id}/regenerate-secret', [AdminController::class, 'regenerateChildInstanceSecret']);
        Route::post('/child-instances/generate-code', [AdminController::class, 'generateChildRegistrationCode']);

        Route::post("/users/{id}/fund", [AdminController::class, 'fundUser']);

        // Irreversible — see ResetWebsiteController's docblock for exactly
        // what's wiped vs. kept.
        Route::post('/reset-website', [ResetWebsiteController::class, 'reset']);

        Route::get("/airtime_discount", [AdminController::class, 'airtimeDiscount']);
    });
    Route::get("/system-information-get", [AdminController::class, 'systemInformation']);
});
