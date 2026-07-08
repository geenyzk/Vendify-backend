<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AirtimeToCashController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\ChildCustomerContactController;
use App\Http\Controllers\ChildCustomerMigrationController;
use App\Http\Controllers\ChildDirectiveController;
use App\Http\Controllers\ChildRegistrationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GeneralController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayscribeController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ResetWebsiteController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceControlController;
use App\Http\Controllers\ServiceCostMarginController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VTUServicesController;
use App\Http\Controllers\WalletTransferController;
use App\Http\Controllers\WalletWithdrawalController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WelcomeMessageController;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

Route::post("/login", [AuthenticatedSessionController::class, 'store']);
Route::post("/register", [RegisteredUserController::class, 'store']);
Route::any("/webhook/{type}/{identifier}", [WebhookController::class, 'handle']);

// Public — read before login (landing page, auth screens) so they can show
// the real configured brand name/logo/page-title instead of a hardcoded
// default. Deliberately excludes everything else on General (bank/BVN etc).
Route::get('/branding', [BrandingController::class, 'show']);

Route::post("/forgot-password", [PasswordResetLinkController::class, 'apiStore'])
    ->middleware('throttle:6,1');
Route::post("/reset-password", [NewPasswordController::class, 'apiStore'])
    ->middleware('throttle:6,1');

// Universal Table API reads: any logged-in user (the customer dashboard's
// service-availability widget reads /table/networks as a non-admin).
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/table/{table}', [AdminController::class, 'universalGet']);
    Route::get('/table/{table}/{id}', [AdminController::class, 'universalShow']);
});

// Universal Table API writes: admin only.
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
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

// SECURITY: the entire Universal Table CRUD used to sit here completely
// unauthenticated — GET *and* write *and* delete, on every table in the
// database, including `users` (wallet_balance, user_type — an unauthenticated
// POST /table/users/{id} could self-promote to admin or credit any wallet
// directly) and `providers` (api_key/password/webhook_access in plaintext,
// no $hidden on that model — and writable, which could hijack a payment
// provider's webhook secret and defeat the signature verification added to
// PaymentBase). Only the plain catalog GETs below were ever meant to be
// public (see customerService.ts, the storefront's pre-login product browse).
// Everything else now requires an authenticated admin.

// Route registration is keyed by method+URI, not by where()-constrained
// variants — registering GET /table/{table} a second time (even with a
// different ->where()) doesn't add a second candidate route, it silently
// REPLACES the first in Laravel's route collection. So this can't be split
// into two GET routes with the same URI; the public/private table split is
// enforced inside universalGet() itself instead. See its docblock.
Route::get('/table/{table}', [AdminController::class, 'universalGet']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    // Dead route — no universalInsert() method exists on AdminController,
    // so this already 500s on any call. Left registered (not implemented)
    // rather than expanding scope here; gated the same as the rest in case
    // it's ever wired up.
    Route::post('/insert', [AdminController::class, 'universalInsert']);

    Route::get('/table/{table}/{id}', [AdminController::class, 'universalShow']);
    Route::match(["post", 'put'], '/table/{table}/{id}', [AdminController::class, 'universalCreateOrUpdate']);
    Route::match(["post", 'put'], '/table/{table}', [AdminController::class, 'universalBulkCreateOrUpdate']);
    Route::delete('/table/{table}/{id}', [AdminController::class, 'universalDelete']);
});


Route::get('/test-mail', function () {
    Mail::raw('Test from Laravel', function ($message) {
        $message->to('officialspurconnect@gmail.com')
                ->subject('Test Subject');
        });

        return 'Sent!';
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::get("/user", [AuthenticatedSessionController::class, 'index']);
    // SPA clients should POST to /logout to properly invalidate the session.
    // We allow GET too so browser visits don't break existing behavior.
    Route::match(['get', 'post'], "/logout", [AuthenticatedSessionController::class, 'destroy']);

    Route::put('/account/profile', [AccountController::class, 'updateProfile']);
    Route::put('/account/password', [AccountController::class, 'updatePassword']);
    Route::put('/account/pin', [AccountController::class, 'updatePin']);
    Route::post('/account/virtual-accounts', [AccountController::class, 'generateVirtualAccounts']);

    Route::post('/vtu/{service}', [VTUServicesController::class, 'handle']);
    Route::get('/vtu/{service}/plans', [VTUServicesController::class, 'plan']);
    Route::get('/vtu/{service}/verify', [VTUServicesController::class, 'verify']);
    Route::get('/vtu/{service}/discount', [VTUServicesController::class, 'discountPreview']);
    Route::get('/transactions/report', [TransactionController::class, 'report']);
    Route::get('/search', [SearchController::class, 'userSearch']);

    Route::get('/welcome-message', [WelcomeMessageController::class, 'show']);
    Route::post('/welcome-message/seen', [WelcomeMessageController::class, 'markSeen']);

    // Own in-app notifications — shared by admin and customer alike (both
    // are just Users), scoped to whoever is logged in (no role gate).
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        // Promotion routes
        Route::post('/promotions/validate', [PromotionController::class, 'validate']);
        Route::post('/promotions/apply', [PromotionController::class, 'apply']);

    Route::prefix("customer")->group(function(){
        Route::get('/stats', [CustomerController::class, 'stats']);
        Route::get('/referral-stats', [CustomerController::class, 'referralStats']);
        Route::post('/{id}/convert-referral', [CustomerController::class, 'convertReferralToWallet']);
        Route::get('/account/upgrade-tiers', [CustomerController::class, 'upgradeTiers']);
        Route::post('/account/upgrade', [CustomerController::class, 'upgrade']);
        Route::get('/airtime-to-cash', [AirtimeToCashController::class, 'myRequests']);
        Route::post('/airtime-to-cash', [AirtimeToCashController::class, 'submit']);

        // Wallet-to-wallet — instant, PIN-gated, no admin review (an
        // internal ledger move, no real money leaves the platform).
        Route::get('/wallet-transfer/lookup', [WalletTransferController::class, 'lookup']);
        Route::post('/wallet-transfer', [WalletTransferController::class, 'send']);

        // Wallet-to-bank — real money leaves the platform, so it's reserved
        // (debited) on submit and only actually paid out once approved (see
        // Setting::wallet_withdrawal_auto_approve / WalletWithdrawalController).
        Route::get('/wallet-withdrawals/banks', [WalletWithdrawalController::class, 'banks']);
        Route::get('/wallet-withdrawals', [WalletWithdrawalController::class, 'myRequests']);
        Route::post('/wallet-withdrawals', [WalletWithdrawalController::class, 'submit']);
    });

    Route::prefix("admin")->middleware('admin')->group(function () {
        Route::middleware('permission:customers')->group(function () {
            // store used to be registered withoutMiddleware as a stub — now
            // that it actually creates users it must sit behind admin+permission.
            Route::resource('users', UserController::class)
                ->only(['index', 'store', 'show', 'update', 'destroy']);
            Route::post('/users/{id}/impersonate', [UserController::class, 'impersonate']);
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
            Route::post('/reset-website', [ResetWebsiteController::class, 'reset']);
        });

        Route::middleware('permission:transactions')->group(function () {
            Route::put('/transactions/{id}/status', [TransactionController::class, 'updateStatus']);
            Route::post('/transactions/{id}/refund', [TransactionController::class, 'refund']);
            Route::get('/transactions/prune-preview', [TransactionController::class, 'prunePreview']);
            Route::post('/transactions/prune', [TransactionController::class, 'pruneNow']);
        });

        Route::get('/search', [SearchController::class, 'adminSearch']);

        Route::middleware('permission:wallets')->group(function () {
            Route::post("/users/{id}/fund", [AdminController::class, 'fundUser']);

            Route::get('/wallet-withdrawals', [WalletWithdrawalController::class, 'adminIndex']);
            Route::post('/wallet-withdrawals/{withdrawal}/approve', [WalletWithdrawalController::class, 'approve']);
            Route::post('/wallet-withdrawals/{withdrawal}/reject', [WalletWithdrawalController::class, 'reject']);
        });

        Route::middleware('permission:support')->group(function () {
            Route::post('/broadcast', [BroadcastController::class, 'send']);
            Route::post('/broadcast/audience-count', [BroadcastController::class, 'audienceCount']);
            Route::get('/broadcast/users-search', [BroadcastController::class, 'searchUsers']);
            Route::get('/broadcast/history', [BroadcastController::class, 'history']);
        });

        Route::middleware('permission:airtime_to_cash')->group(function () {
            Route::get('/airtime-to-cash', [AirtimeToCashController::class, 'adminIndex']);
            Route::post('/airtime-to-cash/{atc}/approve', [AirtimeToCashController::class, 'approve']);
            Route::post('/airtime-to-cash/{atc}/reject', [AirtimeToCashController::class, 'reject']);
        });

        // Parent/child affiliate admin plumbing. The first three sat here
        // before the permission-group reorg (06d83b9) dropped them by
        // accident; the affiliates UI calls all of these. Not scoped to a
        // seeded permission — affiliate management is owner-level surface,
        // same as /stats below.
        Route::get('/child-instances/{id}/secret', [AdminController::class, 'childInstanceSecret']);
        Route::post('/child-instances/{id}/regenerate-secret', [AdminController::class, 'regenerateChildInstanceSecret']);
        Route::post('/child-instances/generate-code', [AdminController::class, 'generateChildRegistrationCode']);
        Route::post('/child-instances/{id}/directives', [AdminController::class, 'createChildDirective']);
        Route::post('/child-instances/{id}/customers/{customerId}/migrate', [ChildCustomerMigrationController::class, 'migrate']);
        Route::get('/child-instances/{id}/customers/{customerId}/messages', [ChildCustomerContactController::class, 'index']);
        Route::post('/child-instances/{id}/customers/{customerId}/messages', [ChildCustomerContactController::class, 'send']);

        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/analytics', [AnalyticsController::class, 'index']);
        Route::get('/vendor/{id}/refresh-token', [AdminController::class, 'refreshToken']);
        Route::get('/vendor/{id}/banks', [AdminController::class, 'banks']);
        Route::get("/airtime_discount", [AdminController::class, 'airtimeDiscount']);

        Route::apiResource('discounts', DiscountController::class)
            ->except(['create', 'edit']);
        Route::apiResource('promotions', PromotionController::class)
            ->except(['create', 'edit']);
        Route::apiResource('events', EventController::class)
            ->except(['create', 'edit']);
    });

    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/admin/permissions', [PermissionController::class, 'index']);
    Route::get("/system-information-get", [AdminController::class, 'systemInformation']);

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
    Route::post('/airtime', [PayscribeController::class, 'purchaseAirtime']);
    Route::get('/data/{network}', [PayscribeController::class, 'dataLookup']);
    Route::post('/data', [PayscribeController::class, 'purchaseData']);
    Route::get('/epins', [PayscribeController::class, 'availableEPins']);
    Route::post('/epins', [PayscribeController::class, 'purchasePin']);
    Route::post('/epins/jamb', [PayscribeController::class, 'jambUserLookup']);
    Route::get('/epins/{trans_id}', [PayscribeController::class, 'retrieveEPin']);
    Route::get('/cable/bouquets/{service}', [PayscribeController::class, 'fetchBouquets']);
    Route::post('/cable/validate', [PayscribeController::class, 'validateSmartCard']);
    Route::post('/cable/pay', [PayscribeController::class, 'payCableTv']);
    Route::post('/cable/topup', [PayscribeController::class, 'topUpTv']);
    Route::post('/internet/list', [PayscribeController::class, 'listInternetServices']);
    Route::get('/internet/spectranet/plans', [PayscribeController::class, 'getSpectranetPinPlans']);
    Route::post('/internet/spectranet/vend', [PayscribeController::class, 'purchaseSpectranetPins']);
    Route::post('/electricity/validate', [PayscribeController::class, 'validateElectricity']);
    Route::post('/electricity/pay', [PayscribeController::class, 'electricityPayment']);
    Route::get('/requery/{id}', [PayscribeController::class, 'requeryTransaction']);
});

// // require __DIR__.'/api_v1.php'; // TEMP: missing file, disabled for local smoke test // TEMP: missing file, disabled for local smoke test
