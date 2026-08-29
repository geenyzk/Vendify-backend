<?php

use App\Classes\Vendor\Providers\CheapDataHub;
use App\Classes\Vendor\Providers\VTUNg;
use App\Classes\Vendor\VendorFactory;
use App\Classes\VTUServices\VTUServiceFactory;
use App\Models\DataPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function cheapDataHubVendor(array $overrides = []): Vendor
{
    return Vendor::create(array_merge([
        'name' => 'CheapDataHub',
        'sub_category' => 'cheapdatahub',
        'base_url' => 'https://cheapdatahub.test/api/v1/resellers',
        'api_key' => 'database-key',
        'active' => true,
    ], $overrides));
}

function cheapDataHubPlan(): DataPlan
{
    return DataPlan::create([
        'network' => 'mtn',
        'plan_name' => '1',
        'plan_size' => 'GB',
        'plan_type' => 'SME',
        'validity' => '30 Days',
        'active' => true,
    ]);
}

function standardCheapDataHubPlan(): DataPlan
{
    return DataPlan::create([
        'network' => 'airtel',
        'plan_name' => '1',
        'plan_size' => 'GB',
        'plan_type' => DataPlan::STANDARD_TYPE,
        'validity' => '1 Day',
        'active' => true,
        'pricing' => ['user' => ['type' => 'fiat', 'value' => 50]],
    ]);
}

function mapCheapDataHubPlan(DataPlan $plan, Vendor $vendor, string $bundleId = '46', int $priority = 100): void
{
    DB::table('providerables')->insert([
        'provider_id' => $vendor->id,
        'providerable_id' => $plan->id,
        'providerable_type' => DataPlan::class,
        'external_plan_id' => $bundleId,
        'server_id' => $bundleId,
        'cost_price' => 0,
        'margin_value' => 0,
        'margin_type' => 'fiat',
        'provider_available' => true,
        'provider_enabled' => true,
        'priority' => $priority,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cheapDataHubFormat(CheapDataHub $client, array $response, string $service = 'data'): array
{
    $method = new ReflectionMethod($client, 'formatResponse');
    $method->setAccessible(true);
    return $method->invoke($client, $service, $response);
}

beforeEach(function () {
    config()->set('services.cheapdatahub', [
        'api_key' => 'environment-key',
        'base_url' => 'https://cheapdatahub.test/api/v1/resellers',
        'airtime_network_ids' => ['mtn' => 17],
    ]);
});

test('sends CheapDataHub airtime with bearer api key and configured network id', function () {
    Http::fake(['*/airtime/purchase/' => Http::response([
        'status' => 'true', 'transaction_id' => 449, 'message' => 'Airtime purchase successful',
    ])]);
    $vendor = cheapDataHubVendor(['api_key' => 'provider-record-key']);
    $client = new CheapDataHub($vendor);
    $payload = $client->formatPayload('airtime', [
        'network' => 'mtn', 'phone' => '08012345678', 'amount' => 100,
    ]);
    $response = $client->sendRequest('airtime', $payload);
    $normalized = cheapDataHubFormat($client, array_merge($response, [
        'tx_ref' => 'TXN-AIRTIME-CDH', 'network' => 'mtn', 'phone' => '08012345678', 'amount' => 100,
    ]), 'airtime');

    Http::assertSent(fn ($request) => $request->url() === 'https://cheapdatahub.test/api/v1/resellers/airtime/purchase/'
        && $request->hasHeader('Authorization', 'Bearer provider-record-key')
        && $request['provider_id'] === 17
        && $request['phone_number'] === '08012345678'
        && $request['amount'] === 100);
    expect($normalized)->toMatchArray([
        'status' => 'success', 'transaction_type' => 'airtime_recharge', 'payment_reference' => 449,
    ]);
});

test('CheapDataHub airtime refuses to invent a missing network mapping', function () {
    config()->set('services.cheapdatahub.airtime_network_ids', []);
    expect(fn () => (new CheapDataHub(cheapDataHubVendor()))->formatPayload('airtime', [
        'network' => 'mtn', 'phone' => '08012345678', 'amount' => 100,
    ]))->toThrow(InvalidArgumentException::class, 'network mapping is not configured');
});

test('CheapDataHub provider record key takes precedence over stale environment config', function () {
    Http::fake(['*/wallet/balance/' => Http::response(['status' => 'true', 'data' => ['balance' => 1]])]);
    (new CheapDataHub(cheapDataHubVendor(['api_key' => 'new-admin-key'])))->checkBalance();
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer new-admin-key'));
});

test('normalizes CheapDataHub wallet balance', function () {
    Http::fake(['*/wallet/balance/' => Http::response([
        'status' => 'true',
        'data' => ['balance' => '2,200.50'],
    ])]);

    expect((new CheapDataHub(cheapDataHubVendor()))->checkBalance())->toBe('2200.5');
});

test('sends mapped bundle id and normalizes a successful data purchase reference', function () {
    Http::fake(['*/data/purchase/' => Http::response([
        'status' => 'true',
        'message' => 'Data purchase successful',
        'reference' => 'CDH567890',
    ], 200)]);
    $vendor = cheapDataHubVendor();
    $plan = cheapDataHubPlan();
    mapCheapDataHubPlan($plan, $vendor, '46');
    $client = new CheapDataHub($vendor);
    $payload = $client->formatPayload('data', [
        'data_plan' => $plan->id,
        'phone' => '08012345678',
        'tx_ref' => 'TXN-1',
    ]);
    $response = $client->sendRequest('data', $payload);
    $normalized = cheapDataHubFormat($client, array_merge($response, [
        'tx_ref' => 'TXN-1', 'phone' => '08012345678', 'amount' => 500,
    ]));

    Http::assertSent(fn ($request) => $request['bundle_id'] === 46
        && $request['phone_number'] === '08012345678'
        && $request->hasHeader('Authorization', 'Bearer environment-key'));
    expect($normalized)->toMatchArray([
        'status' => 'success',
        'transaction_reference' => 'TXN-1',
        'payment_reference' => 'CDH567890',
    ]);

    $transaction = Transaction::create(array_merge($normalized, [
        'user_id' => '00000000-0000-0000-0000-000000000001',
        'balance_before' => 1000,
        'balance_after' => 500,
    ]));
    expect($transaction->fresh()->payment_reference)->toBe('CDH567890');
});

test('a missing CheapDataHub mapping is unavailable and fails safely', function () {
    $vendor = cheapDataHubVendor();
    $plan = cheapDataHubPlan();
    $client = new CheapDataHub($vendor);

    expect($client->canServePlan('data', $plan->id))->toBeFalse();
    expect(fn () => $client->formatPayload('data', [
        'data_plan' => $plan->id, 'phone' => '08012345678', 'tx_ref' => 'TXN-2',
    ]))->toThrow(InvalidArgumentException::class, 'has no CheapDataHub mapping');
});

test('CheapDataHub provider errors are normalized without exposing internals', function (int $status, string $message) {
    $normalized = cheapDataHubFormat(new CheapDataHub(cheapDataHubVendor()), [
        '_http_status' => $status,
        'status' => 'false',
        'message' => 'upstream internal details',
        'tx_ref' => 'TXN-ERROR',
    ]);

    expect($normalized['status'])->toBe('fail')
        ->and($normalized['response_message'])->toBe($message)
        ->and($normalized['response_message'])->not->toContain('upstream internal details');
})->with([
    '401' => [401, 'CheapDataHub authentication failed.'],
    '402' => [402, 'CheapDataHub has insufficient vendor balance.'],
    '422' => [422, 'CheapDataHub rejected the purchase details.'],
    '500' => [500, 'CheapDataHub is temporarily unavailable.'],
]);

test('routing selects CheapDataHub only when the selected plan has its mapping', function () {
    $vendor = cheapDataHubVendor();
    $mapped = cheapDataHubPlan();
    mapCheapDataHubPlan($mapped, $vendor, '46');
    $unmapped = cheapDataHubPlan();

    expect(VTUServiceFactory::make('data', 'SME', 'mtn', $mapped->id))->toBeInstanceOf(CheapDataHub::class)
        ->and(VTUServiceFactory::make('data', 'SME', 'mtn', $unmapped->id))->toBeNull();
});

test('existing VTU.ng factory mapping remains unchanged', function () {
    $vendor = Vendor::create([
        'name' => 'VTU.ng', 'sub_category' => 'vtu_ng', 'base_url' => 'https://vtu.test', 'active' => true,
    ]);

    expect(VendorFactory::make($vendor))->toBeInstanceOf(VTUNg::class);
});

test('active STANDARD CheapDataHub plan is returned by the customer catalogue', function () {
    $vendor = cheapDataHubVendor();
    $plan = standardCheapDataHubPlan();
    mapCheapDataHubPlan($plan, $vendor, '81');

    $response = $this->actingAs(User::factory()->create())
        ->getJson('/api/customer/catalog/data-plans');

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $plan->id,
            'network' => 'airtel',
            'plan_type' => 'STANDARD',
            'active' => true,
        ]);
});

test('STANDARD is accepted as purchase metadata and is not sent to CheapDataHub', function () {
    $vendor = cheapDataHubVendor();
    $plan = standardCheapDataHubPlan();
    mapCheapDataHubPlan($plan, $vendor, '81');

    $payload = (new CheapDataHub($vendor))->formatPayload('data', [
        'data_plan' => $plan->id,
        'network' => 'airtel',
        'plan_type' => DataPlan::STANDARD_TYPE,
        'phone' => '08012345678',
        'tx_ref' => 'TXN-STANDARD',
    ]);

    expect($payload)->toMatchArray(['bundle_id' => 81, 'phone_number' => '08012345678'])
        ->and($payload)->not->toHaveKey('plan_type');
});
