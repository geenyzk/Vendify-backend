<?php

use App\Classes\Vendor\Providers\Adex;
use App\Classes\Vendor\Providers\SimVending;
use App\Classes\VTUServices\VTUServiceFactory;
use App\Models\ServiceRoute;
use App\Models\Sim;
use App\Models\SimDevice;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The default-with-failover routing contract: the Service Routing table
 * points airtime/data at the SIM vendor, but resolution only lands there
 * when a SIM can actually serve the vend RIGHT NOW — otherwise it falls
 * through (before any funds are reserved) to the next provider layer, here
 * the legacy stock_vendings assignment.
 */

function createSimFailoverTables(): void
{
    Schema::create('providers', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->string('base_url')->nullable();
        $table->string('name')->nullable();
        $table->string('username')->nullable();
        $table->string('password')->nullable();
        $table->boolean('active')->default(false);
        $table->string('api_key')->nullable();
        $table->string('webhook_access')->default('1');
        $table->string('identifier')->nullable();
        $table->string('category')->nullable();
        $table->string('sub_category')->nullable();
    });

    Schema::create('service_routes', function (Blueprint $table) {
        $table->id();
        $table->string('service_type');
        $table->string('route_key');
        $table->unsignedBigInteger('provider_id')->nullable();
        $table->timestamps();
    });

    Schema::create('stock_vendings', function (Blueprint $table) {
        $table->id();
        $table->string('vtu')->nullable();
        $table->timestamps();
    });

    // Queried first for airtime routing — stays empty so the per-plan layer
    // yields nothing and precedence moves on to service_routes.
    Schema::create('airtime_plans', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('category')->nullable();
        $table->boolean('active')->default(false);
        $table->timestamps();
    });

    Schema::create('sim_devices', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->string('name');
        $table->string('slug')->unique();
        $table->text('shared_secret')->nullable();
        $table->string('status')->default('active');
        $table->timestamp('last_seen_at')->nullable();
        $table->string('app_version')->nullable();
        $table->text('config')->nullable();
        $table->string('registration_code')->nullable();
        $table->timestamp('registration_code_expires_at')->nullable();
        $table->timestamp('registered_at')->nullable();
    });

    Schema::create('sims', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->unsignedBigInteger('sim_device_id');
        $table->unsignedTinyInteger('slot_index');
        $table->string('network');
        $table->string('phone_number')->nullable();
        $table->boolean('supports_airtime')->default(true);
        $table->boolean('supports_data')->default(false);
        $table->decimal('airtime_balance', 12, 2)->default(0);
        $table->decimal('data_balance_mb', 12, 2)->default(0);
        $table->decimal('airtime_low_threshold', 12, 2)->default(1000);
        $table->decimal('data_low_threshold_mb', 12, 2)->default(1024);
        $table->boolean('enabled')->default(true);
        $table->timestamp('balance_reported_at')->nullable();
        $table->string('notes')->nullable();
    });
}

function dropSimFailoverTables(): void
{
    foreach ([
        'sims', 'sim_devices', 'airtime_plans', 'stock_vendings',
        'service_routes', 'providers',
    ] as $table) {
        Schema::dropIfExists($table);
    }
}

/** Routes airtime/vtu → SIM vendor, with an Adex row as the legacy fallback. */
function seedSimFailoverRouting(): Vendor
{
    $simVendor = Vendor::create([
        'name' => 'SIM Vending',
        'sub_category' => 'simvend',
        'active' => true,
        'identifier' => 'simvendfailover',
    ]);

    Vendor::create([
        'name' => 'Fallback Vendor',
        'sub_category' => 'adex',
        'active' => true,
        'base_url' => 'https://fallback.test',
    ]);

    ServiceRoute::create([
        'service_type' => 'airtime',
        'route_key' => 'vtu',
        'provider_id' => $simVendor->id,
    ]);

    \Illuminate\Support\Facades\DB::table('stock_vendings')->insert([
        'vtu' => 'Fallback Vendor',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $simVendor;
}

function makeOnlineSimForFailover(float $airtimeBalance = 10000): Sim
{
    $device = SimDevice::create([
        'name' => 'Failover Phone',
        'slug' => 'sim-failover-device',
        'shared_secret' => 'irrelevant',
        'status' => 'active',
        'last_seen_at' => now(),
        'registered_at' => now(),
    ]);

    return Sim::create([
        'sim_device_id' => $device->id,
        'slot_index' => 0,
        'network' => 'mtn',
        'airtime_balance' => $airtimeBalance,
        'enabled' => true,
    ]);
}

beforeEach(function () {
    dropSimFailoverTables();
    createSimFailoverTables();
});

afterEach(function () {
    dropSimFailoverTables();
    config(['simvending.enabled' => true]);
});

test('an online funded sim resolves the simvend provider', function () {
    seedSimFailoverRouting();
    makeOnlineSimForFailover();

    $handler = VTUServiceFactory::make('airtime', 'vtu', 'mtn', null, 'vtu', 500.0);

    expect($handler)->toBeInstanceOf(SimVending::class);
});

test('with no online device the purchase falls through to the stock-vending fallback', function () {
    seedSimFailoverRouting();
    // Device exists but hasn't checked in within the online window.
    $sim = makeOnlineSimForFailover();
    $sim->device->forceFill([
        'last_seen_at' => now()->subSeconds((int) config('simvending.online_window') + 60),
    ])->saveQuietly();

    $handler = VTUServiceFactory::make('airtime', 'vtu', 'mtn', null, 'vtu', 500.0);

    expect($handler)->toBeInstanceOf(Adex::class);
});

test('a sim whose balance cannot cover the vend plus reserve falls through', function () {
    seedSimFailoverRouting();
    // 500 vend + airtime_reserve headroom > 550 balance.
    makeOnlineSimForFailover(550);

    $handler = VTUServiceFactory::make('airtime', 'vtu', 'mtn', null, 'vtu', 500.0);

    expect($handler)->toBeInstanceOf(Adex::class);
});

test('a wrong-network sim falls through', function () {
    seedSimFailoverRouting();
    makeOnlineSimForFailover()->update(['network' => 'airtel']);

    $handler = VTUServiceFactory::make('airtime', 'vtu', 'mtn', null, 'vtu', 500.0);

    expect($handler)->toBeInstanceOf(Adex::class);
});

test('the master switch disables SIM vending entirely', function () {
    seedSimFailoverRouting();
    makeOnlineSimForFailover();
    config(['simvending.enabled' => false]);

    $handler = VTUServiceFactory::make('airtime', 'vtu', 'mtn', null, 'vtu', 500.0);

    expect($handler)->toBeInstanceOf(Adex::class);
});
