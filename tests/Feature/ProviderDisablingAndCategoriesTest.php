<?php

use App\Classes\VTUServices\VTUServiceFactory;
use App\Models\DataCategory;
use App\Models\DataPlan;
use App\Models\Network;
use App\Models\NetworkType;
use App\Models\ServiceRoute;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function merchandisingPlan(Vendor $vendor): DataPlan
{
    $network = Network::firstOrCreate(['name' => 'MTN'], ['active' => true]);
    $type = NetworkType::firstOrCreate(['name' => DataPlan::STANDARD_TYPE], ['service_type' => 'data', 'active' => true]);
    $network->networkTypes()->syncWithoutDetaching([$type->id => ['service_type' => 'data', 'active' => true]]);
    $plan = DataPlan::create([
        'network' => 'mtn', 'plan_name' => '1', 'plan_size' => 'GB',
        'plan_type' => DataPlan::STANDARD_TYPE, 'network_type_id' => $type->id,
        'validity' => '30 Days', 'active' => true, 'is_draft' => false,
        'pricing' => ['basic' => 600, 'user' => 700],
    ]);
    DB::table('providerables')->insert([
        'provider_id' => $vendor->id, 'providerable_id' => $plan->id,
        'providerable_type' => DataPlan::class, 'cost_price' => 500,
        'margin_value' => 0, 'margin_type' => 'fiat', 'created_at' => now(), 'updated_at' => now(),
    ]);
    return $plan;
}

test('disabled provider plans disappear and reappear without deleting records', function () {
    $vendor = Vendor::create(['name' => 'Old Vendor', 'sub_category' => 'adex', 'active' => true]);
    $plan = merchandisingPlan($vendor);
    expect(DataPlan::customerVisible()->whereKey($plan->id)->exists())->toBeTrue();
    $vendor->update(['active' => false]);
    expect(DataPlan::customerVisible()->whereKey($plan->id)->exists())->toBeFalse()
        ->and(DataPlan::find($plan->id))->not->toBeNull()
        ->and(Vendor::find($vendor->id))->not->toBeNull();
    $vendor->update(['active' => true]);
    expect(DataPlan::customerVisible()->whereKey($plan->id)->exists())->toBeTrue();
});

test('disabled providers are ignored by primary and fallback routing', function () {
    $vendor = Vendor::create(['name' => 'Disabled Vendor', 'sub_category' => 'adex', 'active' => false]);
    $plan = merchandisingPlan($vendor);
    ServiceRoute::create(['service_type' => 'data', 'route_key' => DataPlan::STANDARD_TYPE, 'provider_id' => $vendor->id]);
    expect(VTUServiceFactory::make('data', DataPlan::STANDARD_TYPE, 'mtn', $plan->id, DataPlan::STANDARD_TYPE, 600))->toBeNull();
});

test('manual category and featured state remain independent of automatic category', function () {
    $vendor = Vendor::create(['name' => 'VTU.ng', 'sub_category' => 'vtu_ng', 'active' => true]);
    $plan = merchandisingPlan($vendor);
    $monthly = DataCategory::where('slug', 'monthly')->firstOrFail();
    $social = DataCategory::where('slug', 'social')->firstOrFail();
    $plan->update(['auto_category_id' => $monthly->id, 'manual_category_id' => $social->id, 'is_featured' => true]);
    $plan->update(['auto_category_id' => DataCategory::where('slug', 'weekly')->value('id')]);
    $plan->refresh();
    expect($plan->effective_category->slug)->toBe('social')
        ->and($plan->is_featured)->toBeTrue();
});
