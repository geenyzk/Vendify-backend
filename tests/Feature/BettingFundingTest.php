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
        'name' => 'Bet9ja',
        'slug' => 'bet9ja',
        'provider_code' => 'Bet9ja',
        'biller_id' => 'Bet9ja',
        'active' => true,
        'verification_supported' => true,
        'minimum_amount' => 100,
        'maximum_amount' => 100000,
    ]);
    Vendor::create([
        'name' => 'VTU.ng',
        'base_url' => 'https://vtu.ng/wp-json/api/v2',
        'api_key' => 'test-token',
        'category' => 'vendor',
        'sub_category' => 'vtu_ng',
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
        'provider' => 'bet9ja',
        'customer_id' => 'BET12345',
        'amount' => 1000,
        'pin' => '1234',
        'idempotency_key' => 'betting-test-key-0001',
    ], $overrides);
}

function fakeVerifiedThen(array $funding): void
{
    Http::fakeSequence()
        ->push(['code' => 'success', 'message' => 'Customer Details Retrieved', 'data' => ['customer_name' => 'TEST USER']])
        ->push($funding);
}

test('successful betting funding records and deducts once', function () {
    [$user] = bettingFixture();
    fakeVerifiedThen(['code' => 'success', 'message' => 'ORDER COMPLETED', 'data' => [
        'status' => 'completed-api', 'request_id' => 'UP-1', 'amount_charged' => 1000,
    ]]);

    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload())
        ->assertOk()->assertJsonPath('data.status', 'success');

    expect((float) $user->fresh()->wallet_balance)->toBe(4000.0)
        ->and(Transaction::first()->transaction_type)->toBe('betting_funding');
});

test('invalid betting account is rejected before wallet reservation', function () {
    [$user] = bettingFixture();
    Http::fake(['*' => Http::response(['code' => 'failure', 'message' => 'Invalid customer ID'], 200)]);

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
    Http::fake(['*' => Http::response(['code' => 'success', 'data' => ['customer_name' => 'TEST USER']])]);
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload())->assertStatus(402);
    expect(Transaction::count())->toBe(0);
});

test('amount limits are enforced server side', function (int $amount) {
    [$user] = bettingFixture();
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload(['amount' => $amount]))
        ->assertUnprocessable();
})->with([99, 100001]);

test('provider failure refunds the reservation', function () {
    [$user] = bettingFixture();
    fakeVerifiedThen(['code' => 'failure', 'message' => 'Unable to process', 'data' => ['status' => 'failed-api']]);
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
            return Http::response(['code' => 'success', 'data' => ['customer_name' => 'TEST USER']]);
        }
        throw new \Illuminate\Http\Client\ConnectionException('timeout');
    });
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload())->assertStatus(202);
    expect(Transaction::first()->status)->toBe('pending')->and((float) $user->fresh()->wallet_balance)->toBe(4000.0);
});

test('permission errors are normalized and raw detail stays internal', function () {
    [$user] = bettingFixture();
    fakeVerifiedThen(['code' => 'failure', 'message' => 'Your institution is not allowed to vend for this biller!']);
    $response = $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload());
    $response->assertUnprocessable()
        ->assertJsonPath('message', 'VTU.ng has not authorised betting funding for this provider. Please choose another provider.')
        ->assertJsonMissing(['Your institution is not allowed to vend for this biller!']);
    expect(data_get(Transaction::first()->raw_payload, 'internal_status'))->toBe('provider_permission_denied');
});

test('duplicate submissions return the original transaction without a second vend', function () {
    [$user] = bettingFixture();
    fakeVerifiedThen(['code' => 'success', 'message' => 'ORDER COMPLETED', 'data' => ['status' => 'completed-api']]);
    $payload = bettingPayload();
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', $payload)->assertOk();
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', $payload)->assertOk()->assertJsonPath('data.replayed', true);
    expect(Transaction::count())->toBe(1)->and((float) $user->fresh()->wallet_balance)->toBe(4000.0);
    Http::assertSentCount(2); // verification + one funding request
});

test('pending upstream response keeps the reservation pending', function () {
    [$user] = bettingFixture();
    fakeVerifiedThen(['code' => 'success', 'message' => 'ORDER PROCESSING', 'data' => ['status' => 'processing-api']]);
    $this->actingAs($user, 'sanctum')->postJson('/api/betting/fund', bettingPayload())->assertStatus(202);
    expect(Transaction::first()->status)->toBe('pending')->and((float) $user->fresh()->wallet_balance)->toBe(4000.0);
});
