<?php

use App\Models\BettingProvider;
use App\Models\BettingSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function bettingFixture(float $balance = 5000): array
{
    BettingSetting::create(['enabled' => true]);
    $provider = BettingProvider::create([
        'name' => 'Test Bet',
        'slug' => 'test-bet',
        'provider_code' => 'test-bet',
        'biller_id' => 'test-bet-live-id',
        'active' => true,
        'verification_supported' => true,
        'minimum_amount' => 100,
        'maximum_amount' => 10000,
    ]);
    Vendor::create([
        'name' => 'VTpass',
        'base_url' => 'https://sandbox.vtpass.test/api',
        'api_key' => 'test-key',
        'public_key' => 'test-public-key',
        'category' => 'vendor',
        'sub_category' => 'vtpass',
        'active' => true,
    ]);
    $user = User::create([
        'username' => 'bet-user',
        'fullname' => 'Bet User',
        'email' => 'bet@example.com',
        'phone' => '08000000001',
        'password' => 'password',
        'pin' => '1234',
        'wallet_balance' => $balance,
        'status' => 'active',
    ]);

    return [$user, $provider];
}

function bettingPayload(array $overrides = []): array
{
    return array_merge([
        'provider' => 'test-bet',
        'customer_id' => 'BET12345',
        'amount' => 1000,
        'pin' => '1234',
        'idempotency_key' => 'betting-test-key-0001',
    ], $overrides);
}

function fakeVerifiedThen(array $funding): void
{
    Http::fakeSequence()
        ->push(['code' => '000', 'content' => ['Customer_Name' => 'TEST USER']])
        ->push($funding);
}

test('successful betting funding records and deducts once', function () {
    [$user] = bettingFixture();
    fakeVerifiedThen(['code' => '000', 'response_description' => 'TRANSACTION SUCCESSFUL', 'transactionID' => 'UP-1']);

    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload())
        ->assertOk()->assertJsonPath('data.status', 'success');

    expect((float) $user->fresh()->wallet_balance)->toBe(4000.0)
        ->and(Transaction::first()->transaction_type)->toBe('betting_funding');
});

test('invalid betting account is rejected before wallet reservation', function () {
    [$user] = bettingFixture();
    Http::fake(['*' => Http::response(['code' => '016', 'response_description' => 'Invalid customer ID'], 200)]);

    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload())->assertUnprocessable();
    expect((float) $user->fresh()->wallet_balance)->toBe(5000.0)->and(Transaction::count())->toBe(0);
});

test('unsupported betting provider is rejected', function () {
    [$user] = bettingFixture();
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload(['provider' => 'unknown']))
        ->assertUnprocessable();
});

test('insufficient wallet does not contact funding endpoint', function () {
    [$user] = bettingFixture(500);
    Http::fake(['*' => Http::response(['code' => '000', 'content' => ['Customer_Name' => 'TEST USER']])]);
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload())->assertStatus(402);
    expect(Transaction::count())->toBe(0);
});

test('amount limits are enforced server side', function (int $amount) {
    [$user] = bettingFixture();
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload(['amount' => $amount]))
        ->assertUnprocessable();
})->with([99, 10001]);

test('provider failure refunds the reservation', function () {
    [$user] = bettingFixture();
    fakeVerifiedThen(['code' => '016', 'response_description' => 'Unable to process']);
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload())->assertUnprocessable();
    expect((float) $user->fresh()->wallet_balance)->toBe(5000.0)
        ->and(Transaction::first()->status)->toBe('fail');
});

test('provider timeout remains pending for safe reconciliation', function () {
    [$user] = bettingFixture();
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            return Http::response(['code' => '000', 'content' => ['Customer_Name' => 'TEST USER']]);
        }
        throw new \Illuminate\Http\Client\ConnectionException('timeout');
    });
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload())->assertStatus(202);
    expect(Transaction::first()->status)->toBe('pending')->and((float) $user->fresh()->wallet_balance)->toBe(4000.0);
});

test('permission errors are normalized and raw detail stays internal', function () {
    [$user] = bettingFixture();
    fakeVerifiedThen(['code' => '016', 'response_description' => 'Your institution is not allowed to vend for this biller!']);
    $response = $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload());
    $response->assertUnprocessable()->assertJsonMissing(['Your institution is not allowed to vend for this biller!']);
    expect(data_get(Transaction::first()->raw_payload, 'internal_status'))->toBe('provider_permission_denied');
});

test('duplicate submissions return the original transaction without a second vend', function () {
    [$user] = bettingFixture();
    fakeVerifiedThen(['code' => '000', 'response_description' => 'TRANSACTION SUCCESSFUL']);
    $payload = bettingPayload();
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', $payload)->assertOk();
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', $payload)->assertOk()->assertJsonPath('data.replayed', true);
    expect(Transaction::count())->toBe(1)->and((float) $user->fresh()->wallet_balance)->toBe(4000.0);
    Http::assertSentCount(2); // verification + one funding request
});

test('pending upstream response keeps the reservation pending', function () {
    [$user] = bettingFixture();
    fakeVerifiedThen(['code' => '099', 'response_description' => 'PROCESSING']);
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload())->assertStatus(202);
    expect(Transaction::first()->status)->toBe('pending')->and((float) $user->fresh()->wallet_balance)->toBe(4000.0);
});
