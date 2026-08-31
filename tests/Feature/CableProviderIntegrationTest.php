<?php

use App\Classes\Vendor\Providers\CheapDataHub;
use App\Classes\Vendor\Providers\VTUNg;
use App\Models\CablePlan;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function cableVendor(string $name, string $subCategory): Vendor
{
    return Vendor::create([
        'name' => $name,
        'sub_category' => $subCategory,
        'base_url' => $subCategory === 'cheapdatahub'
            ? 'https://cdh.test/api/v1/resellers'
            : 'https://vtu.test/api/v2',
        'api_key' => 'test-token',
        'active' => true,
    ]);
}

function cablePlan(string $service = 'dstv', string $name = 'Compact'): CablePlan
{
    return CablePlan::create([
        'cable_network' => $service,
        'plan_name' => $name,
        'active' => true,
        'charge_fee' => ['user' => ['type' => 'fiat', 'value' => 0]],
    ]);
}

function mapCable(CablePlan $plan, Vendor $vendor, string $providerPlanId, string $service): void
{
    DB::table('providerables')->insert([
        'provider_id' => $vendor->id,
        'providerable_id' => $plan->id,
        'providerable_type' => CablePlan::class,
        'external_plan_id' => $providerPlanId,
        'server_id' => $providerPlanId,
        'provider_service_id' => $service,
        'provider_plan_name' => $plan->plan_name,
        'cost_price' => 19000,
        'margin_value' => 0,
        'margin_type' => 'fiat',
        'provider_available' => true,
        'provider_enabled' => true,
        'priority' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('CheapDataHub cable purchase uses only its documented mapping fields', function () {
    Http::fake(['*/cable/purchase/' => Http::response([
        'status' => 'true', 'message' => 'Cable subscription successful', 'reference' => 'CDH987654',
    ])]);
    $vendor = cableVendor('CheapDataHub', 'cheapdatahub');
    $plan = cablePlan();
    mapCable($plan, $vendor, '123', 'dstv');
    $client = new CheapDataHub($vendor);
    $payload = $client->formatPayload('cable', [
        'cable_plan' => $plan->id, 'iuc' => '1234567890', 'phone' => '08012345678',
    ]);
    $client->sendRequest('cable', $payload);

    expect($payload)->toBe(['plan_id' => 123, 'cardnumber' => '1234567890', 'phone' => '08012345678']);
    Http::assertSent(fn ($request) => $request->url() === 'https://cdh.test/api/v1/resellers/cable/purchase/'
        && $request->data() === $payload);
});

test('VTU cable change uses its own service and variation mapping', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $vendor = cableVendor('VTU.ng', 'vtung');
    $plan = cablePlan('showmax', 'Mobile');
    mapCable($plan, $vendor, '2701', 'showmax');

    expect((new VTUNg($vendor))->formatPayload('cable', [
        'cable_plan' => $plan->id, 'iuc' => 'customer@example.com',
        'subscription_type' => 'change', 'tx_ref' => 'CABLE-1',
    ]))->toMatchArray([
        'request_id' => 'vendify_CABLE-1', 'customer_id' => 'customer@example.com',
        'service_id' => 'showmax', 'variation_id' => '2701', 'subscription_type' => 'change',
    ]);
});

test('VTU renew amount is read from server verification cache and not the request', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $vendor = cableVendor('VTU.ng', 'vtung');
    $plan = cablePlan('dstv');
    mapCable($plan, $vendor, '2701', 'dstv');
    Cache::put('cable-verification:'.$user->id.':dstv:'.hash('sha256', '1234567890'), [
        'verified' => true, 'renewal_amount' => 19000,
    ]);

    $payload = (new VTUNg($vendor))->formatPayload('cable', [
        'cable_plan' => $plan->id, 'iuc' => '1234567890', 'subscription_type' => 'renew',
        'renewal_amount' => 1, 'tx_ref' => 'CABLE-2',
    ]);
    expect($payload['amount'])->toBe(19000.0);
});

test('VTU cable sync is idempotent and updates provider availability', function () {
    Http::fake(['*/variations/tv' => Http::sequence()
        ->push(['data' => [[
            'variation_id' => 2701, 'service_name' => 'DStv', 'service_id' => 'DSTV',
            'package_bouquet' => 'Compact', 'price' => '19000', 'availability' => 'Available',
        ]]])
        ->push(['data' => [[
            'variation_id' => 2701, 'service_name' => 'DStv', 'service_id' => 'dstv',
            'package_bouquet' => 'Compact Plus', 'price' => '21000', 'availability' => 'Unavailable',
        ]]])]);
    $vendor = cableVendor('VTU.ng', 'vtung');
    $client = new VTUNg($vendor);
    $client->syncCablePlans();
    $client->syncCablePlans();

    expect(CablePlan::count())->toBe(1)
        ->and(DB::table('providerables')->where('providerable_type', CablePlan::class)->count())->toBe(1);
    $mapping = DB::table('providerables')->where('providerable_type', CablePlan::class)->first();
    expect((bool) $mapping->provider_available)->toBeFalse()
        ->and((float) $mapping->provider_price)->toBe(21000.0)
        ->and($mapping->provider_plan_name)->toBe('Compact Plus');
});

test('disabled VTU provider does not sync or reactivate cable plans', function () {
    Http::fake();
    $vendor = cableVendor('VTU.ng', 'vtung');
    $vendor->update(['active' => false]);
    $summary = (new VTUNg($vendor->fresh()))->syncCablePlans();
    expect($summary['disabled'])->toBeTrue()->and(CablePlan::count())->toBe(0);
    Http::assertNothingSent();
});

test('customer cable catalogue is lean and query count stays constant as plans grow', function () {
    $user = User::factory()->create();
    $vendor = cableVendor('VTU.ng', 'vtung');
    foreach (range(1, 12) as $index) {
        $plan = cablePlan('dstv', "Package {$index}");
        mapCable($plan, $vendor, (string) (2700 + $index), 'dstv');
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->actingAs($user)->getJson('/api/customer/cable/catalog')->assertOk();
    $queries = DB::getQueryLog();

    expect($response->json('data'))->toHaveCount(12)
        ->and(count($queries))->toBeLessThanOrEqual(5);
    expect(array_keys($response->json('data.0')))->toBe([
        'service', 'service_name', 'plan', 'plan_id', 'price', 'availability',
    ]);
    expect(json_encode($response->json('data')))->not->toContain('provider')
        ->not->toContain('external_plan_id')
        ->not->toContain('cost_price');
});
