<?php


use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PayscribeController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\VTUServicesController;
use App\Http\Controllers\ServiceControlController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;

use App\Http\Controllers\PromotionController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\WelcomeMessageController;
use App\Http\Controllers\ServiceCostMarginController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\GeneralController;

// Public — read before login (landing page, auth screens) so they can show
// the real configured brand name/logo/page-title instead of a hardcoded
// default. Deliberately excludes everything else on General (bank/BVN etc).
Route::get('/branding', [BrandingController::class, 'show']);

Route::post("/login", [AuthenticatedSessionController::class, 'store']);
Route::post("/register", [RegisteredUserController::class, 'store']);
Route::post("/forgot-password", [PasswordResetLinkController::class, 'apiStore'])
    ->middleware('throttle:6,1');
Route::post("/reset-password", [NewPasswordController::class, 'apiStore'])
    ->middleware('throttle:6,1');
Route::any("/webhook/{type}/{identifier}", [WebhookController::class, 'handle']);

// Universal Table API reads: any logged-in user (the customer dashboard's
// service-availability widget reads /table/networks as a non-admin).
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/table/{table}', [AdminController::class, 'universalGet']);
    Route::get('/table/{table}/{id}', [AdminController::class, 'universalShow']);
});

// Universal Table API writes: admin only. This used to be wide open with no
// auth at all — every write (create/update/delete/bulk/reorder) against any
// table was reachable by anyone, unauthenticated.
Route::middleware(['auth:sanctum', 'user_type:admin'])->group(function () {
    Route::post('/insert', [AdminController::class, 'universalInsert']);
    Route::post('/table/{table}', [AdminController::class, 'universalCreateOrUpdate']);
    Route::put('/table/{table}/{id}', [AdminController::class, 'universalCreateOrUpdate']);
    Route::post('/table/{table}/reorder', [AdminController::class, 'reorder']);
    Route::post('/table/{table}/bulk', [AdminController::class, 'universalBulkCreateOrUpdate']);
    Route::put('/table/{table}/bulk', [AdminController::class, 'universalBulkCreateOrUpdate']);
    Route::delete('/table/{table}/{id}', [AdminController::class, 'universalDelete']);
    Route::delete('/table/{table}', [AdminController::class, 'universalBulkDelete']);

    // File upload — not reachable via the generic Universal Table API,
    // which only accepts a JSON body, not multipart form data.
    Route::post('/general/logo', [GeneralController::class, 'uploadLogo']);
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::get("/user", [AuthenticatedSessionController::class, 'index']);
    // SPA clients should POST to /logout to properly invalidate the session.
    // We allow GET too so browser visits don't break existing behavior.
    Route::match(['get', 'post'], "/logout", [AuthenticatedSessionController::class, 'destroy']);

    // Self-service account settings — shared by the admin and customer
    // "Settings" pages alike, scoped to whoever is logged in (no role gate).
    Route::put('/account/profile', [AccountController::class, 'updateProfile']);
    Route::put('/account/password', [AccountController::class, 'updatePassword']);
    Route::put('/account/pin', [AccountController::class, 'updatePin']);
    Route::post('/account/virtual-accounts', [AccountController::class, 'generateVirtualAccounts']);

    Route::post('/vtu/{service}', [VTUServicesController::class, 'handle']);
    Route::get('/vtu/{service}/plans', [VTUServicesController::class, 'plan']);
    Route::get('/vtu/{service}/verify', [VTUServicesController::class, 'verify']);
    Route::get('/vtu/{service}/discount', [VTUServicesController::class, 'discountPreview']);
    Route::post('/transactions/report', [TransactionController::class, 'report']);

    Route::get('/welcome-message', [WelcomeMessageController::class, 'show']);
    Route::post('/welcome-message/seen', [WelcomeMessageController::class, 'markSeen']);

        // Promotion routes
        Route::post('/promotions/validate', [PromotionController::class, 'validate']);
        Route::post('/promotions/apply', [PromotionController::class, 'apply']);

    Route::prefix("customer")->group(function(){
        Route::get('/stats', [CustomerController::class, 'stats']);
        Route::post('/{id}/convert-referral', [CustomerController::class, 'convertReferralToWallet']);
        Route::get('/account/upgrade-tiers', [CustomerController::class, 'upgradeTiers']);
        Route::post('/account/upgrade', [CustomerController::class, 'upgrade']);
    });


    Route::prefix("admin")->middleware('user_type:admin')->group(function () {
        Route::resource('users', UserController::class)
            ->withoutMiddleware(['auth:sanctum', 'user_type:admin'])
            ->only(['store']);

        Route::middleware('permission:customers')->group(function () {
            Route::resource('users', UserController::class)
                ->only(['show', 'update', 'destroy', 'index']);
            Route::resource('roles', RoleController::class);
            Route::get('/roles/{id}/users', [RoleController::class, 'users']);
            Route::get('/permissions', [PermissionController::class, 'index']);
            Route::resource('service-cost-margins', ServiceCostMarginController::class);
            Route::get('/roles/{roleId}/cost-margins', [ServiceCostMarginController::class, 'byRole']);
            Route::get('/roles/{roleId}/cost-margins/{serviceType}', [ServiceCostMarginController::class, 'getByService']);
            Route::post('/roles/{roleId}/cost-margins/bulk', [ServiceCostMarginController::class, 'bulkUpdateByRole']);
        });

        Route::middleware('permission:settings')->group(function () {
            Route::resource('controls', ServiceControlController::class);
            Route::resource('templates', TemplateController::class);
            Route::get('/welcome-message', [WelcomeMessageController::class, 'adminShow']);
            Route::put('/welcome-message', [WelcomeMessageController::class, 'upsert']);
            Route::delete('/welcome-message', [WelcomeMessageController::class, 'destroy']);
        });

        // Transaction status override & refund (money-moving, admin-only).
        Route::middleware('permission:transactions')->group(function () {
            Route::put('/transactions/{id}/status', [TransactionController::class, 'updateStatus']);
            Route::post('/transactions/{id}/refund', [TransactionController::class, 'refund']);
            Route::get('/transactions/prune-preview', [TransactionController::class, 'prunePreview']);
            Route::post('/transactions/prune', [TransactionController::class, 'pruneNow']);
        });

        Route::middleware('permission:wallets')->group(function () {
            Route::post("/users/{id}/fund", [AdminController::class, 'fundUser']);
        });

        Route::middleware('permission:support')->group(function () {
            Route::post('/broadcast', [AdminController::class, 'broadcast']);
        });

        // Not clearly owned by any single permission slug — gated by
        // user_type:admin at the group level only.
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/analytics', [AnalyticsController::class, 'index']);
        Route::get('/vendor/{id}/refresh-token', [AdminController::class, 'refreshToken']);
        Route::get('/vendor/{id}/banks', [AdminController::class, 'banks']);
        Route::get("/airtime_discount", [AdminController::class, 'airtimeDiscount']);

        // Discount — a flash-sale-style price cut the platform applies
        // automatically (no code needed), scoped to a service type and
        // optionally one network, for a flat percentage or fixed amount,
        // optionally time-boxed. See Discount::getDiscountedAmount.
        Route::apiResource('discounts', DiscountController::class)
            ->except(['create', 'edit']);

        // Promo codes — a separate concept from Discount: an opt-in code
        // (or admin-triggered "auto") reduction with its own
        // eligibility/date/usage-limit rules.
        Route::apiResource('promotions', PromotionController::class)
            ->except(['create', 'edit']);

        // Events — admin-defined conditions (e.g. "refer 5 friends", "spend
        // ₦50,000 on data") a user must fulfil to earn a badge, cash, or
        // both. See EventService for how each metric is computed and
        // TransactionService::process()/RegisteredUserController::store()
        // for where awarding is triggered.
        Route::apiResource('events', EventController::class)
            ->except(['create', 'edit']);
    });


    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/admin/permissions', [PermissionController::class, 'index']);

    Route::get("/system-information-get", [AdminController::class, 'systemInformation']);
    // Email verification endpoints (API)
    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::post('verify-email-code', [VerifyEmailController::class, 'verifyCode'])
        ->middleware('throttle:6,1')
        ->name('verification.code');
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

/**
 * payscribe integration routes
 */
Route::prefix('payscribe')->group(function () {
    // Airtime
    Route::post('/airtime', [PayscribeController::class, 'purchaseAirtime']);

    // Data
    Route::get('/data/{network}', [PayscribeController::class, 'dataLookup']);
    Route::post('/data', [PayscribeController::class, 'purchaseData']);

    // ePins
    Route::get('/epins', [PayscribeController::class, 'availableEPins']);
    Route::post('/epins', [PayscribeController::class, 'purchasePin']);
    Route::post('/epins/jamb', [PayscribeController::class, 'jambUserLookup']);
    Route::get('/epins/{trans_id}', [PayscribeController::class, 'retrieveEPin']);

    // Cable
    Route::get('/cable/bouquets/{service}', [PayscribeController::class, 'fetchBouquets']);
    Route::post('/cable/validate', [PayscribeController::class, 'validateSmartCard']);
    Route::post('/cable/pay', [PayscribeController::class, 'payCableTv']);
    Route::post('/cable/topup', [PayscribeController::class, 'topUpTv']);

    // Internet
    Route::post('/internet/list', [PayscribeController::class, 'listInternetServices']);
    Route::get('/internet/spectranet/plans', [PayscribeController::class, 'getSpectranetPinPlans']);
    Route::post('/internet/spectranet/vend', [PayscribeController::class, 'purchaseSpectranetPins']);

    // Electricity
    Route::post('/electricity/validate', [PayscribeController::class, 'validateElectricity']);
    Route::post('/electricity/pay', [PayscribeController::class, 'electricityPayment']);

    // Requery
    Route::get('/requery/{id}', [PayscribeController::class, 'requeryTransaction']);
});

require __DIR__.'/api_v1.php';
