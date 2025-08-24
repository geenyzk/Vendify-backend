<?php

<<<<<<< HEAD
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
=======
<<<<<<< HEAD
use Illuminate\Support\Facades\Mail;
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
=======
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
>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4

Route::post("/login", [AuthenticatedSessionController::class, 'store']);
Route::post("/register", [RegisteredUserController::class, 'store']);
Route::any("/webhook/{type}/{identifier}", [WebhookController::class, 'handle']);

Route::post('/insert', [AdminController::class, 'universalInsert']);
<<<<<<< HEAD

=======
<<<<<<< HEAD
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
Route::get('/table/{table}', [AdminController::class, 'universalGet']);


// Update a record by ID in a table universalBulkCreateOrUpdate
Route::get('/table/{table}/{id}', [AdminController::class, 'universalShow']);
<<<<<<< HEAD
Route::match(["post", 'put'],'/table/{table}/{id}', [AdminController::class, 'universalCreateOrUpdate']);
Route::match(["post", 'put'],'/table/{table}', [AdminController::class, 'universalBulkCreateOrUpdate']);
=======
Route::match(["post", 'put'], '/table/{table}/{id}', [AdminController::class, 'universalCreateOrUpdate']);
Route::match(["post", 'put'], '/table/{table}', [AdminController::class, 'universalBulkCreateOrUpdate']);
=======

Route::get('/table/{table}', [AdminController::class, 'universalGet']);


// Update a record by ID in a table universalBulkCreateOrUpdate
Route::get('/table/{table}/{id}', [AdminController::class, 'universalShow']);
Route::match(["post", 'put'],'/table/{table}/{id}', [AdminController::class, 'universalCreateOrUpdate']);
Route::match(["post", 'put'],'/table/{table}', [AdminController::class, 'universalBulkCreateOrUpdate']);
>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
Route::delete('/table/{table}/{id}', [AdminController::class, 'universalDelete']);


Route::get('/test-mail', function () {
    Mail::raw('Test from Laravel', function ($message) {
        $message->to('officialspurconnect@gmail.com')
<<<<<<< HEAD
                ->subject('Test Subject');
        });

        return 'Sent!';
=======
<<<<<<< HEAD
            ->subject('Test Subject');
    });

    return 'Sent!';
=======
                ->subject('Test Subject');
        });

        return 'Sent!';
>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
});


Route::middleware(['auth:sanctum'])->group(function () {
    Route::get("/user", [AuthenticatedSessionController::class, 'index']);
    Route::get("/logout", [AuthenticatedSessionController::class, 'destroy']);
    Route::post('/vtu/{service}', [VTUServicesController::class, 'handle']);
    Route::get('/vtu/{service}/plans', [VTUServicesController::class, 'plan']);
    Route::get('/vtu/{service}/verify', [VTUServicesController::class, 'verify']);
    Route::get('/transactions/report', [TransactionController::class, 'report']);

<<<<<<< HEAD
    Route::prefix("customer")->group(function(){
=======
<<<<<<< HEAD
    Route::prefix("customer")->group(function () {
=======
    Route::prefix("customer")->group(function(){
>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
        Route::post('/{id}/convert-referral', [CustomerController::class, 'convertReferralToWallet']);
        Route::post('/account/upgrade', [CustomerController::class, 'upgrade']);
    });

<<<<<<< HEAD
    Route::prefix("admin")->group(function (){
        Route::resource('users', UserController::class);
        Route::resource('controls', ServiceControlController::class);

=======
<<<<<<< HEAD
    Route::prefix("admin")->group(function () {
        Route::resource('users', UserController::class)
            ->withoutMiddleware('auth:sanctum')
            ->only(['store']);

        Route::resource('users', UserController::class)
            ->only(['show', 'update', 'destroy', 'index']);

        Route::resource('controls', ServiceControlController::class);
=======
    Route::prefix("admin")->group(function (){
        Route::resource('users', UserController::class);
        Route::resource('controls', ServiceControlController::class);

>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::post('/broadcast', [AdminController::class, 'broadcast']);
        Route::get('/vendor/{id}/refresh-token', [AdminController::class, 'refreshToken']);

        Route::post("/users/{id}/fund", [AdminController::class, 'fundUser']);

        Route::get("/airtime_discount", [AdminController::class, 'airtimeDiscount']);
    });
<<<<<<< HEAD
    Route::get("/system-information-get", [AdminController::class, 'systemInformation']);
});
=======
<<<<<<< HEAD

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
=======
    Route::get("/system-information-get", [AdminController::class, 'systemInformation']);
});
>>>>>>> 5a8861e (Jush)
>>>>>>> bbdf8dbc93811b942956ea2015f977bbc20327d4
