<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiManagerController;
use App\Http\Controllers\AirtimeToCashController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AppReleaseController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionSecurityController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\ChildCustomerContactController;
use App\Http\Controllers\ChildCustomerMigrationController;
use App\Http\Controllers\ChildDirectiveController;
use App\Http\Controllers\ChildFundingController;
use App\Http\Controllers\ChildRegistrationController;
use App\Http\Controllers\ChildTunnelController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerCatalogController;
use App\Http\Controllers\CustomerDashboardController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GeneralController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OgdamsWebhookController;
use App\Http\Controllers\PayscribeController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ResetWebsiteController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceControlController;
use App\Http\Controllers\ServiceCostMarginController;
use App\Http\Controllers\ServiceRoutingController;
use App\Http\Controllers\SimDeviceAdminController;
use App\Http\Controllers\SimDeviceController;
use App\Http\Controllers\SimDeviceRegistrationController;
use App\Http\Controllers\SimJobController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VTUServicesController;
use App\Http\Controllers\WalletTransferController;
use App\Http\Controllers\WalletWithdrawalController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WelcomeMessageController;
use Illuminate\Support\Facades\Route;

// Public — read before login (landing page, auth screens) so they can show
// the real configured brand name/logo/page-title instead of a hardcoded
// default. Deliberately excludes everything else on General (bank/BVN etc).
Route::get('/branding', [BrandingController::class, 'show']);
Route::get('/branding/logo', [BrandingController::class, 'logo']);

// Public app distribution — the "Download app" page and the mobile shell's
// update check read these. Binaries are streamed (never via /storage) so we
// set the APK mime and count installs. Admin upload lives in the admin group.
Route::get('/app/latest', [AppReleaseController::class, 'latest']);
Route::get('/app/download', [AppReleaseController::class, 'download']);
Route::get('/app/download/{id}', [AppReleaseController::class, 'download'])->whereNumber('id');

Route::post('/login', [AuthenticatedSessionController::class, 'store']);
Route::post('/register', [RegisteredUserController::class, 'store']);
Route::post('/auth/refresh', [SessionSecurityController::class, 'refresh'])
    ->middleware('throttle:auth.refresh');
Route::post('/forgot-password', [PasswordResetLinkController::class, 'apiStore'])
    ->middleware('throttle:6,1');
Route::post('/reset-password', [NewPasswordController::class, 'apiStore'])
    ->middleware('throttle:6,1');
Route::any('/webhook/{type}/{identifier}', [WebhookController::class, 'handle']);
Route::get('/webhooks/ogdams', OgdamsWebhookController::class);

// Pull/ack half of the parent<->child channel — the child polls these on
// its own cron cadence (see child_backend's ParentSyncPullDirectives).
// This is a client-polling RPC, not an unsolicited webhook, so it gets its
// own route group + middleware rather than sharing the {type}/{identifier}
// shape above.
Route::prefix('child')->middleware('verify.child.hmac')->group(function () {
    Route::get('/{slug}/directives', [ChildDirectiveController::class, 'index']);
    Route::post('/{slug}/directives/{id}/ack', [ChildDirectiveController::class, 'ack']);

    // Parent-managed funding: the child requests a virtual account for a
    // customer, then pulls/acks the credit events the parent raises when those
    // accounts are funded (see ChildFundingController).
    Route::post('/{slug}/virtual-accounts', [ChildFundingController::class, 'requestAccount']);
    Route::get('/{slug}/credit-events', [ChildFundingController::class, 'pullCreditEvents']);
    Route::post('/{slug}/credit-events/{id}/ack', [ChildFundingController::class, 'ackCreditEvent']);
});

// Not HMAC-protected — the child has no shared_secret yet at this point.
// Trust is bootstrapped by the one-time registration_code itself (see
// AdminController::generateChildRegistrationCode / ChildRegistrationController).
Route::post('/child/register', [ChildRegistrationController::class, 'register']);

// SIM vending device channel — Android agents hosting the platform's own
// SIMs heartbeat their stock, claim queued vend jobs, and ack outcomes.
// Same HMAC scheme as the child channel (see SIM_VENDING_PROTOCOL.md).
Route::prefix('sim')->middleware('verify.sim.hmac')->group(function () {
    Route::get('/{slug}/config', [SimDeviceController::class, 'config']);
    Route::post('/{slug}/heartbeat', [SimDeviceController::class, 'heartbeat']);
    Route::post('/{slug}/jobs/claim', [SimJobController::class, 'claim']);
    Route::post('/{slug}/jobs/{id}/ack', [SimJobController::class, 'ack']);
});

// Not HMAC-protected — trust bootstrapped by the one-time registration
// code, exactly like /child/register above.
Route::post('/sim/register', [SimDeviceRegistrationController::class, 'register']);

// Route tunneling — this app doubles as an adex-protocol provider so a
// reroute_provider directive can point a child's provider slot HERE and the
// parent performs the child's transactions (see ChildTunnelController).
// Paths must match what the child's ApiSending::AdexApi client calls; POST
// /user does not collide with the sanctum GET /user above.
Route::post('/user', [ChildTunnelController::class, 'authenticate']);
Route::post('/data', [ChildTunnelController::class, 'data']);
Route::post('/topup', [ChildTunnelController::class, 'topup']);

// Universal Table API reads: any logged-in user (the customer dashboard's
// service-availability widget reads /table/networks as a non-admin).
Route::middleware(['auth:sanctum', 'secure.session'])->group(function () {
    Route::get('/table/{table}', [AdminController::class, 'universalGet']);
    Route::get('/table/{table}/{id}', [AdminController::class, 'universalShow']);
});

// Universal Table API writes: admin only. This used to be wide open with no
// auth at all — every write (create/update/delete/bulk/reorder) against any
// table was reachable by anyone, unauthenticated.
Route::middleware(['auth:sanctum', 'secure.session', 'user_type:admin'])->group(function () {
    Route::post('/insert', [AdminController::class, 'universalInsert']);
    // Literal sub-routes (/reorder, /bulk) MUST be registered before the
    // "/{id}" wildcard — otherwise "PUT /table/{table}/bulk" matches the
    // wildcard with {id}="bulk", which wraps the whole request as one record
    // with id "bulk" and 500s ("Incorrect integer value: 'bulk'").
    Route::post('/table/{table}/reorder', [AdminController::class, 'reorder']);
    Route::post('/table/{table}/bulk', [AdminController::class, 'universalBulkCreateOrUpdate']);
    Route::put('/table/{table}/bulk', [AdminController::class, 'universalBulkCreateOrUpdate']);
    Route::post('/table/{table}', [AdminController::class, 'universalCreateOrUpdate']);
    Route::put('/table/{table}/{id}', [AdminController::class, 'universalCreateOrUpdate']);
    Route::delete('/table/{table}', [AdminController::class, 'universalBulkDelete']);
    Route::delete('/table/{table}/{id}', [AdminController::class, 'universalDelete']);

    // File upload — not reachable via the generic Universal Table API,
    // which only accepts a JSON body, not multipart form data.
    Route::post('/general/logo', [GeneralController::class, 'uploadLogo']);

    // Mobile app (APK) distribution management. Upload is multipart, so it
    // also cannot go through the Universal Table API.
    Route::get('/app/releases', [AppReleaseController::class, 'index']);
    Route::post('/app/releases', [AppReleaseController::class, 'store']);
    Route::delete('/app/releases/{id}', [AppReleaseController::class, 'destroy'])->whereNumber('id');
});

Route::middleware(['auth:sanctum', 'secure.session'])->group(function () {
    Route::get('/user', [AuthenticatedSessionController::class, 'index']);
    // SPA clients should POST to /logout to properly invalidate the session.
    // We allow GET too so browser visits don't break existing behavior.
    Route::match(['get', 'post'], '/logout', [AuthenticatedSessionController::class, 'destroy']);
    Route::post('/impersonation/stop', [UserController::class, 'stopImpersonating']);
    Route::get('/session', [SessionSecurityController::class, 'status']);
    Route::post('/session/extend', [SessionSecurityController::class, 'extend'])
        ->middleware('throttle:session.extend');
    Route::get('/security/sessions', [SessionSecurityController::class, 'index']);
    Route::delete('/security/sessions/{authSession}', [SessionSecurityController::class, 'destroy']);
    Route::post('/security/sessions/logout-others', [SessionSecurityController::class, 'destroyOthers']);
    Route::post('/security/sessions/logout-all', [SessionSecurityController::class, 'destroyAll'])
        ->middleware(['recent.auth', 'throttle:sensitive-auth']);
    Route::post('/security/re-authenticate', [SessionSecurityController::class, 'reauthenticate'])
        ->middleware('throttle:sensitive-auth');
    Route::post('/security/unlock', [SessionSecurityController::class, 'unlock'])
        ->middleware('throttle:sensitive-auth');

    // Self-service account settings — shared by the admin and customer
    // "Settings" pages alike, scoped to whoever is logged in (no role gate).
    Route::put('/account/profile', [AccountController::class, 'updateProfile']);
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->middleware('recent.auth');
    Route::put('/account/pin', [AccountController::class, 'updatePin'])->middleware('recent.auth');
    Route::post('/account/virtual-accounts', [AccountController::class, 'generateVirtualAccounts']);
    Route::get('/wallet/funding-account', [AccountController::class, 'fundingAccount']);

    Route::post('/vtu/{service}', [VTUServicesController::class, 'handle']);
    Route::get('/vtu/{service}/plans', [VTUServicesController::class, 'plan']);
    Route::get('/vtu/{service}/verify', [VTUServicesController::class, 'verify']);
    Route::get('/vtu/{service}/discount', [VTUServicesController::class, 'discountPreview']);
    Route::get('/vtu/{service}/active-discount', [VTUServicesController::class, 'activeDiscount']);
    Route::post('/transactions/report', [TransactionController::class, 'report']);
    Route::get('/transactions/{id}/status', [TransactionController::class, 'showOwn'])->whereNumber('id');
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
    Route::post('/promotions/validate', [PromotionController::class, 'validatePromotion']);
    Route::post('/promotions/apply', [PromotionController::class, 'apply']);

    Route::prefix('customer')->group(function () {
        Route::get('/catalog/networks', [CustomerCatalogController::class, 'networks']);
        Route::get('/catalog/data-plans', [CustomerCatalogController::class, 'dataPlans']);
        Route::get('/dashboard', [CustomerDashboardController::class, 'show']);
        Route::get('/stats', [CustomerController::class, 'stats']);
        Route::get('/referral-stats', [CustomerController::class, 'referralStats']);
        Route::post('/{id}/convert-referral', [CustomerController::class, 'convertReferralToWallet']);
        Route::get('/account/upgrade-tiers', [CustomerController::class, 'upgradeTiers']);
        Route::post('/account/upgrade', [CustomerController::class, 'upgrade']);

        // Airtime to cash — manually reviewed, not an instant purchase (see
        // AirtimeToCashController), so it's not routed through /vtu/{service}.
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

    Route::prefix('admin')->middleware('user_type:admin')->group(function () {
        Route::resource('users', UserController::class)
            ->only(['store']);

        Route::middleware('permission:customers')->group(function () {
            Route::resource('users', UserController::class)
                ->only(['index', 'store', 'show', 'update', 'destroy']);
            Route::post('/users/{id}/impersonate', [UserController::class, 'impersonate'])->middleware('recent.auth');
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
            Route::post('/data-plans/bulk-pricing', [AdminController::class, 'bulkUpdateDataPlanPricing']);
            // Before the resource route, else "variables" is captured as
            // templates/{template}.
            Route::get('templates/variables', [TemplateController::class, 'variables']);
            Route::resource('templates', TemplateController::class);
            Route::get('/welcome-message', [WelcomeMessageController::class, 'adminShow']);
            Route::put('/welcome-message', [WelcomeMessageController::class, 'upsert']);
            Route::delete('/welcome-message', [WelcomeMessageController::class, 'destroy']);
        });

        // Dynamic vendor routing per service category (replaces the fixed
        // stock_vendings screen) — see ServiceRoutingController. Kept at plain
        // admin level (not permission:settings) to match the APIs → Provider /
        // Gateway screens it sits beside, which any admin can manage.
        Route::get('/service-routing', [ServiceRoutingController::class, 'index']);
        Route::put('/service-routing', [ServiceRoutingController::class, 'update']);

        // Transaction status override & refund (money-moving, admin-only).
        Route::middleware('permission:transactions')->group(function () {
            Route::put('/transactions/{id}/status', [TransactionController::class, 'updateStatus']);
            Route::post('/transactions/{id}/refund', [TransactionController::class, 'refund']);
            Route::post('/transactions/{id}/recheck-provider', [TransactionController::class, 'recheckProvider']);
            Route::get('/transactions/prune-preview', [TransactionController::class, 'prunePreview']);
            Route::post('/transactions/prune', [TransactionController::class, 'pruneNow']);
        });

        Route::get('/search', [SearchController::class, 'adminSearch']);

        Route::middleware('permission:wallets')->group(function () {
            Route::post('/users/{id}/fund', [AdminController::class, 'fundUser']);

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

        // AI Manager — in-app assistant that reads live site data and proposes
        // gated admin actions. The `ai_manager` permission opens the assistant;
        // any action it proposes is re-checked against that action's own
        // permission at approval time (see AiManagerService::approve).
        // Audit trail — read-only by design. There is no write route: entries
        // come only from App\Support\AuditLogger, and retention is handled by
        // the scheduled audit:prune command, so the log can't be edited away.
        Route::middleware('permission:audit_logs')->prefix('audit-logs')->group(function () {
            Route::get('/', [AuditLogController::class, 'index']);
            Route::get('/filters', [AuditLogController::class, 'filters']);
        });

        Route::middleware('permission:ai_manager')->prefix('ai')->group(function () {
            Route::get('/usage', [AiManagerController::class, 'usage']);
            Route::get('/tools', [AiManagerController::class, 'tools']);
            Route::get('/conversations', [AiManagerController::class, 'index']);
            Route::post('/conversations', [AiManagerController::class, 'store']);
            Route::get('/conversations/{id}', [AiManagerController::class, 'show']);
            Route::post('/conversations/{id}/messages', [AiManagerController::class, 'sendMessage']);
            Route::post('/conversations/{id}/stream', [AiManagerController::class, 'streamMessage']);
            Route::delete('/conversations/{id}', [AiManagerController::class, 'destroy']);
            Route::post('/actions/{id}/approve', [AiManagerController::class, 'approveAction']);
            Route::post('/actions/{id}/reject', [AiManagerController::class, 'rejectAction']);

            // Proactive monitoring alerts (written by the AiMonitor
            // middleware) — drive the floating AI button / topbar badge.
            Route::get('/alerts', [AiManagerController::class, 'alerts']);
            Route::post('/alerts/{id}/acknowledge', [AiManagerController::class, 'acknowledgeAlert']);
            Route::post('/alerts/acknowledge-all', [AiManagerController::class, 'acknowledgeAllAlerts']);
        });

        // Airtime to cash review queue — a distinct reviewer capability from
        // "transactions" (which covers status overrides/refunds on already-
        // completed purchases), so it gets its own permission slug.
        Route::middleware('permission:airtime_to_cash')->group(function () {
            Route::get('/airtime-to-cash', [AirtimeToCashController::class, 'adminIndex']);
            Route::post('/airtime-to-cash/{atc}/approve', [AirtimeToCashController::class, 'approve']);
            Route::post('/airtime-to-cash/{atc}/reject', [AirtimeToCashController::class, 'reject']);
        });

        // Parent/child affiliate admin plumbing. Not scoped to a seeded
        // permission — affiliate management is owner-level surface, same
        // as /stats below.
        Route::get('/child-instances/{id}/secret', [AdminController::class, 'childInstanceSecret']);
        Route::post('/child-instances/{id}/regenerate-secret', [AdminController::class, 'regenerateChildInstanceSecret']);
        Route::post('/child-instances/generate-code', [AdminController::class, 'generateChildRegistrationCode']);
        Route::post('/child-instances/{id}/directives', [AdminController::class, 'createChildDirective']);
        Route::delete('/child-instances/{id}/directives/{directiveId}', [AdminController::class, 'deleteChildDirective']);
        Route::post('/child-instances/{id}/customers/email-and-migrate', [ChildCustomerMigrationController::class, 'emailAndMigrate']);
        Route::post('/child-instances/{id}/customers/bulk-migrate', [ChildCustomerMigrationController::class, 'bulkMigrate']);
        Route::post('/child-instances/{id}/customers/{customerId}/migrate', [ChildCustomerMigrationController::class, 'migrate']);
        Route::post('/child-instances/{id}/customers/messages', [ChildCustomerContactController::class, 'sendBulk']);
        Route::get('/child-instances/{id}/customers/{customerId}/messages', [ChildCustomerContactController::class, 'index']);
        Route::post('/child-instances/{id}/customers/{customerId}/messages', [ChildCustomerContactController::class, 'send']);

        // SIM vending fleet — its own admin section, deliberately separate
        // from external-provider ("APIs") management: these are the
        // platform's own SIMs/agent phones, not a configurable vendor.
        // Owner-level surface like child-instances (no permission slug).
        Route::get('/sim-vending/overview', [SimDeviceAdminController::class, 'overview']);
        Route::post('/sim-vending/devices/generate-code', [SimDeviceAdminController::class, 'generateCode']);
        Route::post('/sim-vending/devices/{id}/regenerate-secret', [SimDeviceAdminController::class, 'regenerateSecret']);
        Route::put('/sim-vending/devices/{id}', [SimDeviceAdminController::class, 'updateDevice']);
        Route::delete('/sim-vending/devices/{id}', [SimDeviceAdminController::class, 'deleteDevice']);
        Route::post('/sim-vending/devices/{id}/sims', [SimDeviceAdminController::class, 'createSim']);
        Route::put('/sim-vending/devices/{id}/sims/{simId}', [SimDeviceAdminController::class, 'updateSim']);

        Route::post('/reset-website', [ResetWebsiteController::class, 'reset']);

        // Run framework/database migrations from the admin UI (dangerous)
        Route::post('/migrate-db', [AdminController::class, 'migrateDb']);

        // Not clearly owned by any single permission slug — gated by
        // user_type:admin at the group level only.
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/analytics', [AnalyticsController::class, 'index']);
        Route::get('/provider-types', [AdminController::class, 'providerTypes']);
        Route::get('/gateway-types', [AdminController::class, 'gatewayTypes']);
        Route::get('/vendor/{id}/refresh-token', [AdminController::class, 'refreshToken']);
        Route::get('/vendor/{id}/banks', [AdminController::class, 'banks']);
        Route::get('/vendor/{id}/plan-sync-status', [AdminController::class, 'vendorPlanSyncStatus']);
        Route::post('/vendor/{id}/sync-plans', [AdminController::class, 'syncVendorPlans']);
        Route::get('/vendor/{id}/plan-imports', [AdminController::class, 'vendorPlanImports']);
        Route::patch('/vendor/{id}/plan-mappings/{planId}', [AdminController::class, 'updateVendorPlanMapping']);
        Route::post('/vendor/{id}/plan-imports', [AdminController::class, 'importVendorPlanPrices']);
        Route::get('/airtime_discount', [AdminController::class, 'airtimeDiscount']);

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

    Route::get('/system-information-get', [AdminController::class, 'systemInformation']);
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

    Route::post('/airtime/funding', [VTUServicesController::class, 'airtimeFunding'])->middleware(['auth:sanctum', 'secure.session']);
    Route::post('/airtime/topup', [VTUServicesController::class, 'airtimeTopup'])->middleware(['auth:sanctum', 'secure.session']);
    Route::post('/data/purchase', [VTUServicesController::class, 'dataPurchase'])->middleware(['auth:sanctum', 'secure.session']);
    Route::post('/cable', [VTUServicesController::class, 'cableSubscription'])->middleware(['auth:sanctum', 'secure.session']);
    Route::post('/electricity', [VTUServicesController::class, 'electricityPayment'])->middleware(['auth:sanctum', 'secure.session']);
});

/**
 * payscribe integration routes
 */
Route::prefix('payscribe')->group(function () {
    // Airtime
    Route::post('/airtime', [PayscribeController::class, 'purchaseAirtime'])->middleware(['auth:sanctum', 'secure.session']);

    // Data
    Route::get('/data/{network}', [PayscribeController::class, 'dataLookup']);
    Route::post('/data', [PayscribeController::class, 'purchaseData'])->middleware(['auth:sanctum', 'secure.session']);

    // ePins
    Route::get('/epins', [PayscribeController::class, 'availableEPins']);
    Route::post('/epins', [PayscribeController::class, 'purchasePin'])->middleware(['auth:sanctum', 'secure.session']);
    Route::post('/epins/jamb', [PayscribeController::class, 'jambUserLookup']);
    Route::get('/epins/{trans_id}', [PayscribeController::class, 'retrieveEPin']);

    // Cable
    Route::get('/cable/bouquets/{service}', [PayscribeController::class, 'fetchBouquets']);
    Route::post('/cable/validate', [PayscribeController::class, 'validateSmartCard']);
    Route::post('/cable/pay', [PayscribeController::class, 'payCableTv'])->middleware(['auth:sanctum', 'secure.session']);
    Route::post('/cable/topup', [PayscribeController::class, 'topUpTv'])->middleware(['auth:sanctum', 'secure.session']);

    // Internet
    Route::post('/internet/list', [PayscribeController::class, 'listInternetServices']);
    Route::get('/internet/spectranet/plans', [PayscribeController::class, 'getSpectranetPinPlans']);
    Route::post('/internet/spectranet/vend', [PayscribeController::class, 'purchaseSpectranetPins'])->middleware(['auth:sanctum', 'secure.session']);

    // Electricity
    Route::post('/electricity/validate', [PayscribeController::class, 'validateElectricity']);
    Route::post('/electricity/pay', [PayscribeController::class, 'electricityPayment'])->middleware(['auth:sanctum', 'secure.session']);

    // Requery
    Route::get('/requery/{id}', [PayscribeController::class, 'requeryTransaction']);
});

require __DIR__.'/api_v1.php';
