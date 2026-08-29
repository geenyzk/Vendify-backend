<?php

use App\Models\BillPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.env' => 'testing',
        'electricity.sandbox_enabled' => true,
        'electricity.sandbox_allowed_emails' => [' SANDBOX@EXAMPLE.COM '],
    ]);
    BillPlan::create(['disco' => 'IKEDC (Ikeja Electric)', 'min' => 500, 'max' => 100000, 'active' => true]);
    Http::fake();
    Notification::fake();
});

function sandboxElectricityUser(string $email = 'sandbox@example.com'): User
{
    return User::create([
        'username' => uniqid('power_'), 'fullname' => 'Power Tester',
        'email' => $email, 'phone' => '08000000001',
        'password' => 'password', 'pin' => '1234', 'wallet_balance' => 5000,
        'status' => 'active',
    ]);
}

function verifySandboxMeter($test, User $user, string $meter, string $type = 'prepaid')
{
    return $test->actingAs($user, 'sanctum')->getJson('/api/vtu/electricity/verify?'.http_build_query([
        'identifier' => $meter, 'meter_type' => $type, 'disco' => 'IKEDC (Ikeja Electric)',
    ]));
}

test('sandbox prepaid and postpaid meters verify without contacting VTU.ng', function () {
    $user = sandboxElectricityUser();
    verifySandboxMeter($this, $user, '1111111111111')->assertOk()
        ->assertJsonPath('data.name', 'VENDIFY TEST CUSTOMER')->assertJsonPath('data.sandbox', true);
    verifySandboxMeter($this, $user, '2222222222222', 'postpaid')->assertOk()
        ->assertJsonPath('data.name', 'VENDIFY POSTPAID TEST')->assertJsonPath('data.sandbox', true);
    Http::assertNothingSent();
});

test('unknown and failure sandbox meters return controlled errors', function () {
    $user = sandboxElectricityUser();
    verifySandboxMeter($this, $user, '9999999999999')->assertUnprocessable()
        ->assertJsonPath('message', 'This meter number is not available in electricity sandbox mode.');
    verifySandboxMeter($this, $user, '4000000000000')->assertUnprocessable()
        ->assertJsonPath('message', 'This meter could not be verified.');
    verifySandboxMeter($this, $user, '5000000000000')->assertStatus(503);
    Http::assertNothingSent();
});

test('timeout sandbox meter produces timeout handling', function () {
    verifySandboxMeter($this, sandboxElectricityUser(), '3000000000000')
        ->assertStatus(504)->assertJsonPath('type', 'provider_timeout');
    Http::assertNothingSent();
});

test('sandbox prepaid purchase creates a flagged token transaction without touching wallet or VTU.ng', function () {
    config(['app.env' => 'production']);
    $user = sandboxElectricityUser();
    $before = (float) $user->wallet_balance;

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/vtu/electricity', [
        'disco' => 'IKEDC (Ikeja Electric)', 'meter_number' => '1111111111111',
        'meter_type' => 'prepaid', 'amount' => 1000, 'bypass' => false,
        'pin' => '1234', 'tx_ref' => 'EL-SANDBOX-0001',
    ])->assertOk()->assertJsonPath('data.sandbox', true)->assertJsonPath('data.status', 'success');

    expect($response->json('data.token'))->toMatch('/^\d{4}( \d{4}){4}$/')
        ->and((float) $user->fresh()->wallet_balance)->toBe($before);
    $transaction = Transaction::where('transaction_reference', 'EL-SANDBOX-0001')->firstOrFail();
    expect($transaction->is_sandbox)->toBeTrue()->and($transaction->provider)->toBe('electricity_sandbox');
    Http::assertNothingSent();
});

test('sandbox postpaid purchase succeeds without generating a token', function () {
    $user = sandboxElectricityUser();
    $this->actingAs($user, 'sanctum')->postJson('/api/vtu/electricity', [
        'disco' => 'IKEDC (Ikeja Electric)', 'meter_number' => '2222222222222',
        'meter_type' => 'postpaid', 'amount' => 1000, 'bypass' => false,
        'pin' => '1234', 'tx_ref' => 'EL-SANDBOX-0002',
    ])->assertOk()->assertJsonPath('data.token', null);
    expect((float) $user->fresh()->wallet_balance)->toBe(5000.0);
    Http::assertNothingSent();
});

test('production permits electricity sandbox only for an allowlisted user', function () {
    config(['app.env' => 'production', 'electricity.sandbox_enabled' => true]);
    $this->actingAs(sandboxElectricityUser(), 'sanctum')->getJson('/api/vtu/electricity/sandbox-status')
        ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.test_prepaid_meter', '1111111111111');
});

test('empty allowlist and disabled flag deny sandbox access', function () {
    $user = sandboxElectricityUser();
    config(['electricity.sandbox_allowed_emails' => []]);
    $this->actingAs($user, 'sanctum')->getJson('/api/vtu/electricity/sandbox-status')
        ->assertOk()->assertJsonPath('data.enabled', false);

    config(['electricity.sandbox_allowed_emails' => ['sandbox@example.com'], 'electricity.sandbox_enabled' => false]);
    $this->getJson('/api/vtu/electricity/sandbox-status')
        ->assertOk()->assertJsonPath('data.enabled', false);
});

test('non-allowlisted production user stays on the real VTU.ng flow', function () {
    config(['app.env' => 'production']);
    Provider::create([
        'name' => 'VTU.ng', 'category' => 'vendor', 'sub_category' => 'vtu_ng',
        'base_url' => 'https://vtu.test/wp-json/api/v2', 'api_key' => 'live-test-token', 'active' => true,
    ]);
    Http::fake(['https://vtu.test/wp-json/api/v2/verify-customer' => Http::response([
        'code' => 'failure', 'message' => 'Live provider rejected the meter.', 'data' => ['status' => 400],
    ], 400)]);

    $user = sandboxElectricityUser('normal-customer@example.com');
    $this->actingAs($user, 'sanctum')->getJson('/api/vtu/electricity/sandbox-status')
        ->assertOk()->assertJsonPath('data.enabled', false);
    verifySandboxMeter($this, $user, '1111111111111')->assertStatus(400)
        ->assertJsonPath('message', 'Live provider rejected the meter.');
    Http::assertSent(fn ($request) => $request->url() === 'https://vtu.test/wp-json/api/v2/verify-customer');
});

test('disabled sandbox keeps the existing VTU.ng electricity verification flow', function () {
    config(['electricity.sandbox_enabled' => false]);
    Provider::create([
        'name' => 'VTU.ng', 'category' => 'vendor', 'sub_category' => 'vtu_ng',
        'base_url' => 'https://vtu.test/wp-json/api/v2', 'api_key' => 'live-test-token', 'active' => true,
    ]);
    Http::fake(['https://vtu.test/wp-json/api/v2/verify-customer' => Http::response([
        'code' => 'success', 'message' => 'Customer Details Retrieved',
        'data' => ['customer_name' => 'REAL PROVIDER CUSTOMER'],
    ])]);

    verifySandboxMeter($this, sandboxElectricityUser(), '01234567890')->assertOk()
        ->assertJsonPath('data.name', 'REAL PROVIDER CUSTOMER')
        ->assertJsonMissing(['sandbox' => true]);
    Http::assertSent(fn ($request) => $request->url() === 'https://vtu.test/wp-json/api/v2/verify-customer');
});

test('sandbox transactions are excluded from financial summaries', function () {
    $user = sandboxElectricityUser();
    Transaction::create([
        'user_id' => $user->id, 'transaction_type' => 'electric_bill', 'provider' => 'electricity_sandbox',
        'amount' => 1000, 'status' => 'success', 'transaction_reference' => 'EL-SUMMARY-1', 'is_sandbox' => true,
    ]);
    $summary = Transaction::calculateSummary(now()->subDay(), now()->addDay(), $user->id);
    expect($summary['electric_bill'])->toBe([]);
});
