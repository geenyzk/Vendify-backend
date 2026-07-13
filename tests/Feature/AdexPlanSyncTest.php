<?php

use App\Classes\Vendor\Providers\Adex;
use App\Models\DataPlan;
use App\Models\Role;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
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
        'https://quicklysim.test/api/data-plan' => Http::response([
            [
                'plan_id' => 2,
                'network' => 'MTN',
                'network_type' => 'DATA SHARE',
                'plan_name' => '100MB',
                'validate' => 'WEEKLY',
                'amount' => 150,
            ],
            [
                'plan_id' => 5,
                'network' => 'MTN',
                'network_type' => 'GIFTING',
                'plan_name' => '1GB',
                'validate' => '30 days',
                'amount' => 400,
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
        ->and($draftPlan->providers()->wherePivot('server_id', '2')->exists())->toBeTrue();
});
