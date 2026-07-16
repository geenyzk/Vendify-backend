<?php

use App\Classes\Vendor\Providers\Adex;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\CustomerCatalogController;
use App\Models\DataPlan;
use App\Models\Role;
use App\Models\Vendor;
use App\Support\PerformanceCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Cache::flush();
    Schema::dropIfExists('providerables');
    Schema::dropIfExists('data_plans');
    Schema::dropIfExists('roles');
    Schema::dropIfExists('providers');

    Schema::create('providers', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->string('name')->nullable();
        $table->string('sub_category')->nullable();
        $table->string('category')->nullable();
        $table->string('base_url')->nullable();
        $table->string('username')->nullable();
        $table->string('password')->nullable();
        $table->string('api_key')->nullable();
        $table->boolean('active')->default(true);
        $table->string('identifier')->nullable();
    });

    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->string('name');
        $table->boolean('is_staff')->default(false);
    });

    Schema::create('data_plans', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->string('plan_name')->nullable();
        $table->string('plan_size')->nullable();
        $table->string('plan_type')->nullable();
        $table->string('network')->nullable();
        $table->boolean('active')->default(false);
        $table->boolean('is_draft')->default(false);
        $table->string('validity')->nullable();
        $table->integer('sort_order')->default(0);
        $table->json('pricing')->nullable();
    });

    Schema::create('providerables', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->unsignedBigInteger('provider_id');
        $table->unsignedBigInteger('providerable_id');
        $table->string('providerable_type');
        $table->decimal('cost_price', 12, 2)->nullable();
        $table->decimal('margin_value', 12, 2)->default(0);
        $table->string('margin_type')->nullable();
        $table->string('server_id')->nullable();
        $table->string('external_plan_id')->nullable();
        $table->unsignedBigInteger('fallback_provider_id')->nullable();
        $table->decimal('fallback_cost_price', 12, 2)->nullable();
        $table->decimal('provider_discount', 12, 2)->nullable();
        $table->decimal('fallback_provider_discount', 12, 2)->nullable();
        $table->string('fallback_server_id')->nullable();
    });

    Role::create(['name' => 'user', 'is_staff' => false]);
});

test('adex can fetch remote data plans and create draft catalog entries', function () {
    $vendor = Vendor::create([
        'name' => 'Adex',
        'sub_category' => 'adex',
        'base_url' => 'https://quicklysim.test',
        'active' => true,
    ]);

    Http::fake([
        'https://quicklysim.test/data-plan' => Http::response([
            [
                'plan_id' => 2,
                'network' => 'MTN',
                'network_type' => 'DATA SHARE',
                'plan_name' => '100MB',
                'validate' => 'WEEKLY',
            ],
            [
                'plan_id' => 5,
                'network' => 'MTN',
                'network_type' => 'GIFTING',
                'plan_name' => '1GB',
                'validate' => '30 days',
            ],
        ], 200),
    ]);

    $provider = new Adex($vendor);

    $remotePlans = $provider->fetchRemotePlans();

    expect($remotePlans)->toHaveCount(2)
        ->and($remotePlans[0]['vendor_plan_id'])->toBe('2')
        ->and($remotePlans[0]['network'])->toBe('mtn');

    $summary = $provider->syncPlans();

    expect($summary['created'])->toBe(2)
        ->and($summary['updated'])->toBe(0)
        ->and(DataPlan::count())->toBe(2);

    $draftPlan = DataPlan::where('plan_name', '100')->where('plan_size', 'MB')->first();
    expect($draftPlan)->not->toBeNull()
        ->and($draftPlan->is_draft)->toBeTrue()
        ->and($draftPlan->active)->toBeFalse()
        ->and($draftPlan->providers()->wherePivot('external_plan_id', '2')->exists())->toBeTrue()
        ->and((float) $draftPlan->resolveCostPrice())->toBe(0.0);
});

test('sync ignores an active duplicate plan from another provider', function () {
    $providerA = Vendor::create([
        'name' => 'Adex',
        'sub_category' => 'adex',
        'base_url' => 'https://quicklysim.test',
        'active' => true,
    ]);
    $providerB = Vendor::create([
        'name' => 'Ogdams',
        'sub_category' => 'ogdams',
        'base_url' => 'https://quicklysim.test',
        'active' => true,
    ]);

    $plan = DataPlan::create([
        'network' => 'mtn',
        'plan_type' => 'DATA SHARE',
        'plan_name' => '100',
        'plan_size' => 'MB',
        'validity' => 'WEEKLY',
        'active' => true,
        'is_draft' => false,
        'sort_order' => 0,
        'pricing' => [],
    ]);

    DB::table('providerables')->insert([
        'provider_id' => $providerA->id,
        'providerable_id' => $plan->id,
        'providerable_type' => DataPlan::class,
        'cost_price' => 150,
        'margin_value' => 0,
        'margin_type' => 'fiat',
        'server_id' => 'adex-1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake([
        'https://quicklysim.test/data-plan' => Http::response([
            [
                'plan_id' => 99,
                'network' => 'MTN',
                'network_type' => 'DATA SHARE',
                'plan_name' => '100MB',
                'validate' => 'WEEKLY',
            ],
        ], 200),
    ]);

    $provider = new Adex($providerB);
    $summary = $provider->syncPlans();

    expect($summary['created'])->toBe(0)
        ->and($summary['updated'])->toBe(0)
        ->and(DataPlan::count())->toBe(1)
        ->and(DB::table('providerables')->where('providerable_id', $plan->id)->where('providerable_type', DataPlan::class)->value('provider_id'))->toBe($providerA->id);
});

test('sync preserves an entered cost and does not duplicate a fetched plan', function () {
    $vendor = Vendor::create([
        'name' => 'Adex',
        'sub_category' => 'adex',
        'base_url' => 'https://quicklysim.test',
        'active' => true,
    ]);

    $plan = DataPlan::create([
        'network' => 'mtn',
        'plan_type' => 'DATA SHARE',
        'plan_name' => '100',
        'plan_size' => 'MB',
        'validity' => 'WEEKLY',
        'active' => false,
        'is_draft' => true,
        'sort_order' => 0,
        'pricing' => [],
    ]);

    DB::table('providerables')->insert([
        'provider_id' => $vendor->id,
        'providerable_id' => $plan->id,
        'providerable_type' => DataPlan::class,
        'cost_price' => 200,
        'margin_value' => 0,
        'margin_type' => 'fiat',
        'server_id' => null,
        'external_plan_id' => '88',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake([
        'https://quicklysim.test/data-plan' => Http::response([
            [
                'plan_id' => 88,
                'network' => 'MTN',
                'network_type' => 'DATA SHARE',
                'plan_name' => '100MB',
                'validate' => 'WEEKLY',
            ],
            // A duplicate API row must not create a duplicate local plan.
            [
                'plan_id' => 89,
                'network' => 'MTN',
                'network_type' => 'DATA_SHARE',
                'plan_name' => '100 MB',
                'validate' => 'weekly',
            ],
        ], 200),
    ]);

    $provider = new Adex($vendor);
    $summary = $provider->syncPlans();

    expect($summary['created'])->toBe(0)
        ->and($summary['updated'])->toBe(1)
        ->and($summary['skipped'])->toBe(1)
        ->and(DataPlan::count())->toBe(1)
        ->and(DB::table('providerables')->where('providerable_id', $plan->id)->value('external_plan_id'))->toBe('88')
        ->and((float) DB::table('providerables')->where('providerable_id', $plan->id)->value('cost_price'))->toBe(200.0);
});

test('plans with the same size but different validity are stored separately and remain inactive', function () {
    $vendor = Vendor::create([
        'name' => 'QuicklySIM',
        'sub_category' => 'adex',
        'base_url' => 'https://quicklysim.test',
        'active' => true,
    ]);

    Http::fake([
        'https://quicklysim.test/data-plan' => Http::response([
            ['plan_id' => 10, 'network' => 'MTN', 'network_type' => 'GIFTING', 'plan_name' => '1GB', 'validate' => '1 DAY'],
            ['plan_id' => 11, 'network' => 'MTN', 'network_type' => 'GIFTING', 'plan_name' => '1GB', 'validate' => '30 DAYS'],
        ], 200),
    ]);

    (new Adex($vendor))->syncPlans();

    expect(DataPlan::count())->toBe(2)
        ->and(DataPlan::where('active', true)->count())->toBe(0)
        ->and(DataPlan::where('is_draft', true)->count())->toBe(2)
        ->and(DB::table('providerables')->distinct()->count('external_plan_id'))->toBe(2);
});

test('bulk pricing calculates selling price and only then activates an imported plan', function () {
    $vendor = Vendor::create([
        'name' => 'QuicklySIM',
        'sub_category' => 'adex',
        'base_url' => 'https://quicklysim.test',
        'active' => true,
    ]);

    Http::fake([
        'https://quicklysim.test/data-plan' => Http::response([
            ['plan_id' => 21, 'network' => 'AIRTEL', 'network_type' => 'GIFTING', 'plan_name' => '2GB', 'validate' => '2 DAYS'],
        ], 200),
    ]);
    (new Adex($vendor))->syncPlans();

    $plan = DataPlan::firstOrFail();
    $plan->active = true;
    $plan->save();
    expect($plan->fresh()->active)->toBeFalse();

    $request = Request::create('/admin/vendor/'.$vendor->id.'/plan-imports', 'POST', [
        'markup_percent' => 10,
        'activate' => true,
        'plans' => [['plan_id' => $plan->id, 'cost_price' => 500]],
    ]);
    (new AdminController)->importVendorPlanPrices($request, (string) $vendor->id);

    $pricedPlan = $plan->fresh();
    expect($pricedPlan->active)->toBeTrue()
        ->and($pricedPlan->is_draft)->toBeFalse()
        ->and($pricedPlan->price)->toBe(550.0)
        ->and((float) DB::table('providerables')->where('providerable_id', $plan->id)->value('cost_price'))->toBe(500.0)
        ->and(DB::table('providerables')->where('providerable_id', $plan->id)->value('external_plan_id'))->toBe('21');
});

test('an imported plan can receive a cost price and activate in one generic admin save', function () {
    $vendor = Vendor::create(['name' => 'QuicklySIM', 'sub_category' => 'adex', 'active' => true]);
    $plan = DataPlan::create([
        'network' => 'MTN', 'plan_type' => 'GIFTING', 'plan_name' => '1', 'plan_size' => 'GB',
        'validity' => '30 DAYS', 'active' => false, 'is_draft' => true, 'pricing' => [],
    ]);
    DB::table('providerables')->insert([
        'provider_id' => $vendor->id, 'providerable_id' => $plan->id,
        'providerable_type' => DataPlan::class, 'external_plan_id' => 'external-1',
        'cost_price' => 0, 'margin_value' => 0, 'margin_type' => 'fiat',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $request = Request::create('/admin/table/data_plans/bulk', 'PUT', ['items' => [[
        'id' => $plan->id,
        'active' => true,
        'use_provider_as_providerable' => true,
        'providerable' => ['provider_id' => $vendor->id, 'cost_price' => 450, 'margin_value' => 10, 'margin_type' => 'percentage'],
    ]]]);
    AdminController::universalBulkCreateOrUpdate($request, 'data_plans');

    expect($plan->fresh()->active)->toBeTrue()
        ->and($plan->fresh()->is_draft)->toBeFalse()
        ->and((float) DB::table('providerables')->where('providerable_id', $plan->id)->value('cost_price'))->toBe(450.0)
        ->and(DB::table('providerables')->where('providerable_id', $plan->id)->count())->toBe(1);
});

test('an imported plan with zero cost remains inactive and draft after a generic admin save', function () {
    $vendor = Vendor::create(['name' => 'QuicklySIM', 'sub_category' => 'adex', 'active' => true]);
    $plan = DataPlan::create([
        'network' => 'mtn', 'plan_type' => 'GIFTING', 'plan_name' => '1', 'plan_size' => 'GB',
        'validity' => '30 DAYS', 'active' => false, 'is_draft' => true, 'pricing' => [],
    ]);
    DB::table('providerables')->insert([
        'provider_id' => $vendor->id, 'providerable_id' => $plan->id,
        'providerable_type' => DataPlan::class, 'external_plan_id' => 'external-2',
        'cost_price' => 25, 'margin_value' => 0, 'margin_type' => 'fiat',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $request = Request::create('/admin/table/data_plans/bulk', 'PUT', ['items' => [[
        'id' => $plan->id, 'active' => true, 'use_provider_as_providerable' => true,
        'providerable' => ['provider_id' => $vendor->id, 'cost_price' => 0],
    ]]]);
    AdminController::universalBulkCreateOrUpdate($request, 'data_plans');

    expect($plan->fresh()->active)->toBeFalse()->and($plan->fresh()->is_draft)->toBeTrue();
});

test('catalogue cache is invalidated after an edit and after vendor price import', function () {
    $vendor = Vendor::create(['name' => 'QuicklySIM', 'sub_category' => 'adex', 'active' => true]);
    $plan = DataPlan::create([
        'network' => 'mtn', 'plan_type' => 'GIFTING', 'plan_name' => '1', 'plan_size' => 'GB',
        'validity' => '30 DAYS', 'active' => false, 'is_draft' => true, 'pricing' => [],
    ]);
    DB::table('providerables')->insert([
        'provider_id' => $vendor->id, 'providerable_id' => $plan->id,
        'providerable_type' => DataPlan::class, 'external_plan_id' => 'external-3',
        'cost_price' => 0, 'margin_value' => 0, 'margin_type' => 'fiat',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Cache::forever('catalog:v1:version', 100);
    AdminController::universalBulkCreateOrUpdate(Request::create('/admin/table/data_plans/bulk', 'PUT', ['items' => [[
        'id' => $plan->id, 'plan_name' => '1.5',
    ]]]), 'data_plans');
    $afterEdit = Cache::get('catalog:v1:version');
    expect($afterEdit)->toBeGreaterThan(100);

    (new AdminController)->importVendorPlanPrices(Request::create('/admin/vendor/'.$vendor->id.'/plan-imports', 'POST', [
        'markup_percent' => 10, 'activate' => true,
        'plans' => [['plan_id' => $plan->id, 'cost_price' => 500]],
    ]), (string) $vendor->id);
    expect(Cache::get('catalog:v1:version'))->toBeGreaterThan($afterEdit);
});

test('customer catalogue returns an active configured price immediately and hides draft plans', function () {
    $visible = DataPlan::create([
        'network' => 'MTN', 'plan_type' => 'GIFTING', 'plan_name' => '1', 'plan_size' => 'GB',
        'validity' => '30 DAYS', 'active' => true, 'is_draft' => false,
        'pricing' => ['user' => ['type' => 'fiat', 'value' => 350]],
    ]);
    DataPlan::create([
        'network' => 'AIRTEL', 'plan_type' => 'GIFTING', 'plan_name' => '2', 'plan_size' => 'GB',
        'validity' => '30 DAYS', 'active' => true, 'is_draft' => true,
        'pricing' => ['user' => ['type' => 'fiat', 'value' => 900]],
    ]);

    $payload = (new CustomerCatalogController)->dataPlans(Request::create('/catalog/data-plans', 'GET'))->getData(true);
    $plans = $payload['data'];

    expect($plans)->toHaveCount(1)
        ->and($plans[0]['id'])->toBe($visible->id)
        ->and($plans[0]['network'])->toBe('mtn')
        ->and($plans[0]['price'])->toBe(350.0);
});

test('percentage and missing role pricing resolve to a real price or null, never an invented zero', function () {
    $vendor = Vendor::create(['name' => 'Pricing vendor', 'sub_category' => 'adex', 'active' => true]);
    $plan = DataPlan::create([
        'network' => 'mtn', 'plan_type' => 'GIFTING', 'plan_name' => '1', 'plan_size' => 'GB',
        'validity' => '30 DAYS', 'active' => true, 'is_draft' => false,
        'pricing' => ['user' => ['type' => 'percentage', 'value' => 10]],
    ]);
    DB::table('providerables')->insert([
        'provider_id' => $vendor->id, 'providerable_id' => $plan->id,
        'providerable_type' => DataPlan::class, 'cost_price' => 500,
        'margin_value' => 0, 'margin_type' => 'fiat', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($plan->fresh()->price)->toBe(550.0);

    $plan->pricing = ['affiliate' => ['type' => 'fiat', 'value' => 600]];
    $plan->save();
    expect($plan->fresh()->price)->toBeNull()->and($plan->fresh()->price_ngn)->toBeNull();
});
