<?php

use App\Models\DataPlan;
use App\Models\DataCategory;
use App\Models\Vendor;
use App\Http\Controllers\AdminController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function pricedDataPlan(array $pricing, float $cost = 300): DataPlan
{
    $vendor = Vendor::create([
        'name' => 'Pricing Test Vendor '.uniqid(),
        'sub_category' => 'cheapdatahub',
        'active' => true,
    ]);
    $plan = DataPlan::create([
        'network' => 'airtel',
        'plan_name' => '1',
        'plan_size' => 'GB',
        'plan_type' => DataPlan::STANDARD_TYPE,
        'validity' => '',
        'active' => true,
        'pricing' => $pricing,
    ]);

    DB::table('providerables')->insert([
        'provider_id' => $vendor->id,
        'providerable_id' => $plan->id,
        'providerable_type' => DataPlan::class,
        'server_id' => '70',
        'cost_price' => $cost,
        'margin_value' => 0,
        'margin_type' => 'fiat',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $plan->fresh();
}

test('zero and null role rules fall back to the default cost-based selling price', function () {
    $plan = pricedDataPlan([
        'basic' => ['type' => 'fiat', 'value' => 0],
        'agent' => null,
        'user' => ['type' => 'fiat', 'value' => 0],
    ]);

    expect($plan->priceForRoleKeys(['basic', 'user']))->toBe(300.0)
        ->and($plan->priceForRoleKeys(['agent', 'user']))->toBe(300.0)
        ->and($plan->priceForRoleKeys(['owner', 'user']))->toBe(300.0);
});

test('explicit fixed price applies only to its role and other roles use the default', function () {
    $plan = pricedDataPlan([
        'agent' => ['type' => 'fixed', 'value' => 350],
        'user' => ['type' => 'fiat', 'value' => 0],
    ]);

    expect($plan->priceForRoleKeys(['agent', 'user']))->toBe(350.0)
        ->and($plan->priceForRoleKeys(['basic', 'user']))->toBe(300.0);
});

test('fiat and percentage markups apply exactly once', function () {
    $plan = pricedDataPlan([
        'agent' => ['type' => 'fiat', 'value' => 50],
        'basic' => ['type' => 'percentage', 'value' => 10],
    ]);

    expect($plan->priceForRoleKeys(['agent', 'user']))->toBe(350.0)
        ->and($plan->priceForRoleKeys(['basic', 'user']))->toBe(330.0);
});

test('existing scalar zero rules fall back while positive scalar rules remain fixed prices', function () {
    $plan = pricedDataPlan(['basic' => 0, 'agent' => 350]);

    expect($plan->priceForRoleKeys(['basic', 'user']))->toBe(300.0)
        ->and($plan->priceForRoleKeys(['agent', 'user']))->toBe(350.0);
});

test('partial category and pricing updates preserve active state and provider mapping', function () {
    $plan = pricedDataPlan(['user' => ['type' => 'fiat', 'value' => 0]]);
    $providerId = $plan->providers()->firstOrFail()->id;
    $category = DataCategory::create([
        'name' => 'Social',
        'slug' => 'social',
        'sort_order' => 80,
        'is_active' => true,
    ]);

    AdminController::universalBulkCreateOrUpdate(
        Request::create('/table/data_plans', 'PUT', [
            'items' => [['id' => $plan->id, 'manual_category_id' => $category->id]],
        ]),
        'data_plans',
    );
    AdminController::universalBulkCreateOrUpdate(
        Request::create('/table/data_plans', 'PUT', [
            'items' => [[
                'id' => $plan->id,
                'pricing' => ['agent' => ['type' => 'fixed', 'value' => 350]],
            ]],
        ]),
        'data_plans',
    );

    $fresh = $plan->fresh();
    expect($fresh->active)->toBeTrue()
        ->and($fresh->manual_category_id)->toBe($category->id)
        ->and($fresh->priceForRoleKeys(['agent', 'user']))->toBe(350.0)
        ->and($fresh->resolveCostPrice())->toBe(300.0)
        ->and($fresh->providers()->firstOrFail()->id)->toBe($providerId);
});
