<?php

use App\Classes\Vendor\Providers\Adex;
use App\Http\Controllers\AdminController;
use App\Models\DataPlan;
use App\Models\Role;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
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
