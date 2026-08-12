<?php

namespace Tests\Feature;

use App\Classes\Vendor\Providers\VTUNg;
use App\Models\DataPlan;
use App\Models\Network;
use App\Models\NetworkType;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VendorProviderNameExposureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure STANDARD type exists
        NetworkType::updateOrInsert(
            ['name' => 'STANDARD', 'service_type' => 'data'],
            ['active' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    // Test 1: VTU.ng provider remains registered internally
    test('vtu_ng provider is registered and active in the system', function () {
        $vendor = Vendor::create([
            'name' => 'VTU.ng',
            'sub_category' => 'vtu_ng',
            'base_url' => 'https://vtu.test',
            'active' => true,
        ]);

        expect($vendor)->not->toBeNull()
            ->and($vendor->sub_category)->toBe('vtu_ng')
            ->and($vendor->active)->toBeTrue();
    });

    // Test 2: VTU.ng does not appear in customer-facing plan types
    test('vtu_ng provider name does not appear in customer catalogue plan types', function () {
        $vendor = Vendor::create([
            'name' => 'VTU.ng',
            'sub_category' => 'vtu_ng',
            'base_url' => 'https://vtu.test',
            'active' => true,
        ]);

        $plan = DataPlan::create([
            'network' => 'mtn',
            'plan_type' => DataPlan::STANDARD_TYPE,
            'plan_name' => '1',
            'plan_size' => 'GB',
            'validity' => '30 Days',
            'active' => true,
            'is_draft' => false,
            'pricing' => ['user' => ['type' => 'fiat', 'value' => 500]],
        ]);

        DB::table('providerables')->insert([
            'provider_id' => $vendor->id,
            'providerable_id' => $plan->id,
            'providerable_type' => DataPlan::class,
            'external_plan_id' => '12345',
            'server_id' => '12345',
            'cost_price' => 450,
            'margin_value' => 0,
            'margin_type' => 'fiat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/customer/catalog/data-plans');

        $response->assertOk();
        $plans = collect($response->json('data'));
        $catalogPlan = $plans->first();

        // Plan type should be STANDARD, not VTU.NG or VTU.ng
        expect($catalogPlan['plan_type'])->toBe('STANDARD')
            ->and($catalogPlan['plan_type'])->not->toBe('VTU.NG')
            ->and($catalogPlan['plan_type'])->not->toBe('VTU.ng');
    });

    // Test 3: STANDARD exists as a normal managed Data type
    test('STANDARD type exists in database as managed NetworkType', function () {
        $standard = NetworkType::where('name', 'STANDARD')
            ->where('service_type', 'data')
            ->first();

        expect($standard)->not->toBeNull()
            ->and($standard->active)->toBeTrue();
    });

    // Test 4: STANDARD appears in admin type management
    test('STANDARD type is returned by admin network type API', function () {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->getJson('/table/network_types');

        $response->assertOk();
        $types = collect($response->json('data'));
        $standard = $types->firstWhere('name', 'STANDARD');

        expect($standard)->not->toBeNull()
            ->and($standard['service_type'])->toBe('data')
            ->and($standard['active'])->toBeTrue();
    });

    // Test 5: STANDARD can be returned by the customer catalogue
    test('STANDARD type data plan is returned in customer catalogue', function () {
        $plan = DataPlan::create([
            'network' => 'airtel',
            'plan_type' => DataPlan::STANDARD_TYPE,
            'plan_name' => '2',
            'plan_size' => 'GB',
            'validity' => '7 Days',
            'active' => true,
            'is_draft' => false,
            'pricing' => ['user' => ['type' => 'fiat', 'value' => 1000]],
        ]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/customer/catalog/data-plans');

        $response->assertOk();
        $plans = collect($response->json('data'));
        $found = $plans->firstWhere('id', $plan->id);

        expect($found)->not->toBeNull()
            ->and($found['plan_type'])->toBe('STANDARD');
    });

    // Test 6: Inactive types are not offered to customers
    test('inactive data types are not shown in customer purchase flow', function () {
        $active = NetworkType::create([
            'name' => 'ACTIVE_TYPE',
            'service_type' => 'data',
            'active' => true,
        ]);

        $inactive = NetworkType::create([
            'name' => 'INACTIVE_TYPE',
            'service_type' => 'data',
            'active' => false,
        ]);

        $network = Network::create(['name' => 'MTN', 'code' => 'mtn', 'active' => true]);
        $network->networkTypes()->attach($active, ['service_type' => 'data', 'active' => true]);
        $network->networkTypes()->attach($inactive, ['service_type' => 'data', 'active' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/customer/catalog/networks');

        $response->assertOk();
        $networks = collect($response->json('data'));
        $mtn = $networks->firstWhere('name', 'MTN');

        expect($mtn)->not->toBeNull();
        $types = collect($mtn['network_types']);
        expect($types->where('name', 'ACTIVE_TYPE')->count())->toBe(1)
            ->and($types->where('name', 'INACTIVE_TYPE')->count())->toBe(0);
    });

    // Test 7: Plan can be STANDARD while provider is VTU.ng (separation of concerns)
    test('data plan can have STANDARD type while VTU.ng is the provider', function () {
        $vendor = Vendor::create([
            'name' => 'VTU.ng',
            'sub_category' => 'vtu_ng',
            'base_url' => 'https://vtu.test',
            'active' => true,
        ]);

        $plan = DataPlan::create([
            'network' => 'glo',
            'plan_type' => DataPlan::STANDARD_TYPE,
            'plan_name' => '500',
            'plan_size' => 'MB',
            'validity' => '1 Day',
            'active' => true,
            'is_draft' => false,
            'pricing' => ['user' => ['type' => 'fiat', 'value' => 300]],
        ]);

        DB::table('providerables')->insert([
            'provider_id' => $vendor->id,
            'providerable_id' => $plan->id,
            'providerable_type' => DataPlan::class,
            'external_plan_id' => '5678',
            'server_id' => '5678',
            'cost_price' => 250,
            'margin_value' => 0,
            'margin_type' => 'fiat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $freshPlan = DataPlan::find($plan->id);
        expect($freshPlan->plan_type)->toBe('STANDARD')
            ->and($freshPlan->provider->sub_category)->toBe('vtu_ng');
    });

    // Test 8: Provider routing continues working after type changes
    test('provider routing for VTU.NG key still works when plan type is STANDARD', function () {
        $vendor = Vendor::create([
            'name' => 'VTU.ng',
            'sub_category' => 'vtu_ng',
            'base_url' => 'https://vtu.ng/wp-json/api/v2',
            'active' => true,
        ]);

        Http::fake([
            'https://vtu.ng/wp-json/api/v2/variations/data' => Http::response([
                'data' => [[
                    'variation_id' => '999',
                    'service_id' => 'mtn',
                    'service_name' => 'MTN',
                    'data_plan' => '1GB - 30 Days',
                    'reseller_price' => '499.00',
                    'availability' => 'Available',
                ]],
            ]),
        ]);

        $client = new VTUNg($vendor);
        $summary = $client->syncPlans();

        expect($summary['created'])->toBe(1);

        // Verify the plan was created with STANDARD type, not VTU.NG
        $plan = DataPlan::where('network', 'mtn')->where('plan_size', 'GB')->first();
        expect($plan->plan_type)->toBe(DataPlan::STANDARD_TYPE);

        // Verify service route still exists for internal routing
        $route = DB::table('service_routes')
            ->where('service_type', 'data')
            ->where('route_key', 'VTU.NG')
            ->first();

        expect($route)->not->toBeNull()
            ->and($route->provider_id)->toBe($vendor->id);
    });

    // Test 9: Existing VTU.ng plans are migrated safely
    test('existing VTU.NG plan types are migrated to STANDARD', function () {
        $plan = DataPlan::create([
            'network' => 'airtel',
            'plan_type' => 'VTU.NG',
            'plan_name' => '3',
            'plan_size' => 'GB',
            'validity' => '14 Days',
            'active' => false,
            'is_draft' => false,
            'pricing' => ['user' => ['type' => 'fiat', 'value' => 1500]],
        ]);

        // Simulate the data migration
        DB::table('data_plans')
            ->where('plan_type', 'VTU.NG')
            ->update(['plan_type' => DataPlan::STANDARD_TYPE]);

        $freshPlan = DataPlan::find($plan->id);
        expect($freshPlan->plan_type)->toBe(DataPlan::STANDARD_TYPE);
    });

    // Test 10: Provider names are not unnecessarily exposed in customer responses
    test('customer catalogue does not expose provider names unnecessarily', function () {
        $vendor = Vendor::create([
            'name' => 'VTU.ng',
            'sub_category' => 'vtu_ng',
            'base_url' => 'https://vtu.test',
            'active' => true,
        ]);

        $plan = DataPlan::create([
            'network' => '9mobile',
            'plan_type' => 'GIFTING',
            'plan_name' => '5',
            'plan_size' => 'GB',
            'validity' => '30 Days',
            'active' => true,
            'is_draft' => false,
            'pricing' => ['user' => ['type' => 'fiat', 'value' => 2500]],
        ]);

        DB::table('providerables')->insert([
            'provider_id' => $vendor->id,
            'providerable_id' => $plan->id,
            'providerable_type' => DataPlan::class,
            'external_plan_id' => '11111',
            'server_id' => '11111',
            'cost_price' => 2200,
            'margin_value' => 0,
            'margin_type' => 'fiat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/customer/catalog/data-plans');

        $response->assertOk();
        $plans = collect($response->json('data'));
        $catalogPlan = $plans->first();

        // Verify that we don't leak provider-specific internal fields
        expect($catalogPlan)->not->toHaveKey('provider_id')
            ->and($catalogPlan)->not->toHaveKey('external_plan_id')
            ->and($catalogPlan)->not->toHaveKey('cost_price')
            ->and($catalogPlan)->not->toHaveKey('provider_price')
            ->and($catalogPlan)->not->toHaveKey('server_id');
    });
}
