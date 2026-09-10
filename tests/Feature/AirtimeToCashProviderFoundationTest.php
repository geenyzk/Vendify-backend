<?php

namespace Tests\Feature;

use App\Http\Controllers\AirtimeToCashProviderController;
use App\Models\AirtimeToCashProviderSetting;
use App\Models\AirtimeToCashRequest;
use App\Models\Discount;
use App\Models\Network;
use App\Models\User;
use App\Services\AirtimeToCash\AirtimeToCashProviderManager;
use App\Services\AirtimeToCash\AirtimeToCashProviderService;
use App\Services\AirtimeToCash\AirtimeToCashReconciliationService;
use App\Services\AirtimeToCash\ProviderResult;
use App\Services\AirtimeToCash\Providers\AutomationProvider;
use App\Services\AirtimeToCash\Providers\TwoFastProvider;
use App\Services\AirtimeToCashSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class AirtimeToCashProviderFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'airtime_to_cash.provider_mode_enabled' => true,
            'airtime_to_cash.live_calls_enabled' => false,
            'airtime_to_cash.providers.airtime_to_cash_automation.token' => 'test-automation-token',
            'airtime_to_cash.providers.2fast.token' => 'test-twofast-token',
        ]);
    }

    private function user(): User
    {
        $id = Str::lower(Str::random(10));

        return User::create([
            'username' => $id,
            'fullname' => 'Provider Test User',
            'email' => "{$id}@example.test",
            'phone' => '080'.random_int(10000000, 99999999),
            'password' => 'password',
            'status' => 'active',
            'is_active' => true,
            'is_verified' => true,
            'wallet_balance' => 1000,
        ]);
    }

    private function network(string $name = 'MTN'): Network
    {
        $network = Network::create([
            'name' => $name,
            'active' => true,
            'airtime_to_cash_active' => true,
            'airtime_to_cash_destination_number' => '08030000000',
            'airtime_to_cash_min' => 50,
            'airtime_to_cash_max' => 50000,
        ]);
        Discount::create([
            'name' => "$name Airtime to Cash",
            'service_type' => 'airtimeToCash',
            'network' => strtolower($name),
            'discount_type' => 'percentage',
            'value' => 5,
            'active' => true,
        ]);

        return $network;
    }

    private function enable(string $provider = 'airtime_to_cash_automation'): void
    {
        AirtimeToCashProviderSetting::create(['provider' => $provider, 'enabled' => true, 'priority' => 1]);
    }

    private function automationSequence(array $transfer, array $verify = []): void
    {
        Http::fake([
            'https://automation.airtimetocash.com/api/v1/check/quota/availability' => Http::response(['code' => 5030, 'message' => 'Recipient(s) Available'], 200),
            'https://automation.airtimetocash.com/api/v1/generate/otp' => Http::response(['code' => 2000], 200),
            'https://automation.airtimetocash.com/api/v1/verify/otp' => Http::response($verify ?: [
                'code' => 2000,
                'data' => ['sessionId' => 'safe-session-id', 'airtimeBalance' => 'NGN 1,000'],
            ], 200),
            'https://automation.airtimetocash.com/api/v1/transfer/airtime' => Http::response($transfer, 200),
        ]);
    }

    public function test_legacy_style_request_keeps_manual_processing_default(): void
    {
        $user = $this->user();
        $request = AirtimeToCashRequest::create([
            'user_id' => $user->id,
            'network' => 'mtn',
            'sender_phone' => '08012345678',
            'destination_number' => '08030000000',
            'amount' => 500,
            'payout_amount' => 475,
            'transaction_reference' => 'ATC-LEGACY-'.Str::uuid(),
            'status' => 'pending',
        ]);

        $this->assertSame('manual', $request->fresh()->processing_mode);
        $this->assertNull($request->provider_status);
    }

    public function test_automation_networks_limits_capabilities_and_response_normalization(): void
    {
        $provider = app(AutomationProvider::class);
        $this->assertSame(['MTN', 'AIRTEL', 'GLO', '9MOBILE'], array_column($provider->networks(), 'code'));
        $this->assertSame(1000, $provider->networks()['glo']['max']);
        $this->assertSame(['quota' => true, 'session' => true, 'lookup' => false], $provider->capabilities());

        foreach ([2000 => 'success', 3000 => 'failed', 4000 => 'pending', 4030 => 'auth_error',
            4010 => 'session_expired', 4290 => 'rate_limited', 5030 => 'unavailable'] as $code => $state) {
            $body = ['code' => $code];
            if ($code === 2000) {
                $body['data'] = ['sessionId' => 'session'];
            }
            $this->assertSame($state, $provider->normalize('verify', 200, $body)->state);
        }
        $this->assertSame('unknown', $provider->normalize('convert', 500, [])->state);
        $this->assertSame('unknown', $provider->normalize('convert', 200, ['code' => 2000, 'data' => []])->state);
        $this->assertSame('rate_limited', $provider->normalize('otp', 429, [])->state);
        $this->assertSame('auth_error', $provider->normalize('otp', 401, [])->state);
    }

    public function test_automation_adapter_maps_requests_and_never_retries_transfer(): void
    {
        $this->automationSequence(['code' => 3000, 'message' => 'invalid pin']);
        $provider = app(AutomationProvider::class);
        $this->assertSame('success', $provider->requestOtp('mtn', '08012345678')->state);
        $this->assertSame('success', $provider->verifyOtp('mtn', '08012345678', '654321')->state);
        $result = $provider->convert('mtn', '08012345678', 500, 'ATC-REFERENCE-1234', 'safe-session-id', '1234');
        $this->assertSame('failed', $result->state);
        $this->assertFalse($result->retryable('convert'));
        Http::assertSentCount(3);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/transfer/airtime')
            && $request['networkName'] === 'MTN'
            && $request['reference'] === 'ATC-REFERENCE-1234'
            && $request['pin'] === '1234');
    }

    public function test_automation_accepts_only_the_documented_positive_5030_quota_response(): void
    {
        $provider = app(AutomationProvider::class);
        $this->assertSame('success', $provider->normalize('quota', 200, ['code' => 5030, 'message' => 'Recipient(s) Available'])->state);
        foreach (['Unavailability of recipient!', '', 'Not Recipient(s) Available', 'Recipient(s) Available but transfer pending'] as $message) {
            $this->assertSame('unavailable', $provider->normalize('quota', 200, ['code' => 5030, 'message' => $message])->state);
        }
        $this->assertSame('unavailable', $provider->normalize('convert', 200, ['code' => 5030, 'message' => 'Recipient(s) Available'])->state);
        $this->assertSame('unknown', $provider->normalize('quota', 500, ['code' => 5030, 'message' => 'Recipient(s) Available'])->state);
        $this->assertSame('auth_error', $provider->normalize('otp', 400, ['code' => 4030])->state);
    }

    public function test_twofast_steps_skip_otp_and_conservative_conversion_states(): void
    {
        $provider = app(TwoFastProvider::class);
        $this->assertSame([1, 2], array_column($provider->networks(), 'code'));
        $this->assertSame(['quota' => false, 'session' => false, 'lookup' => true], $provider->capabilities());
        $skip = $provider->normalize('otp', 200, ['status' => 'success', 'skip_otp' => true, 'identifier' => 'identifier']);
        $this->assertTrue($skip->skipOtp);
        $this->assertSame('success', $skip->state);
        $this->assertSame('success', $provider->normalize('verify', 200, [
            'status' => 'success', 'identifier' => 'identifier', 'airtime_balance' => 1500,
        ])->state);
        $completed = $provider->normalize('convert', 200, [
            'status' => 'success', 'message' => 'Airtime received. Your wallet has been credited with NGN 800.00.',
        ]);
        $this->assertSame('success', $completed->state);
        $this->assertSame(800.0, $completed->cost);
        $this->assertSame('pending', $provider->normalize('convert', 200, [
            'status' => 'success', 'message' => 'Awaiting airtime confirmation. You will be credited once received.',
        ])->state);
        $this->assertSame('unknown', $provider->normalize('convert', 200, ['status' => 'success', 'message' => 'OK'])->state);
        $this->assertSame('failed', $provider->normalize('convert', 200, ['status' => 'error', 'message' => 'invalid pin'])->state);
        $this->assertSame('unknown', $provider->normalize('convert', 409, ['status' => 'error'])->state);
    }

    public function test_twofast_request_shapes_and_transaction_lookup(): void
    {
        Http::fakeSequence('https://2fast.com.ng/api/Airtime-To-Cash')
            ->push(['status' => 'success', 'message' => 'OTP sent successfully.'])
            ->push(['status' => 'success', 'identifier' => 'id', 'airtime_balance' => 1000])
            ->push(['status' => 'success', 'message' => 'Awaiting airtime confirmation. You will be credited once received.']);
        Http::fake([
            'https://2fast.com.ng/api/transaction-history' => Http::response([
                'status' => 'success', 'source' => 'wallet_history',
                'data' => ['reference' => 'ATC-SAME-REFERENCE', 'type' => 'Airtime To Cash', 'status' => 'Successful'],
            ]),
        ]);
        $provider = app(TwoFastProvider::class);
        $provider->requestOtp('mtn', '08012345678');
        $provider->verifyOtp('mtn', '08012345678', '123456');
        $this->assertSame('pending', $provider->convert('mtn', '08012345678', 500, 'ATC-SAME-REFERENCE', 'id', '1234')->state);
        $this->assertSame('success', $provider->lookup('ATC-SAME-REFERENCE')->state);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/Airtime-To-Cash')
            && $request['step'] === 3 && $request['reference'] === 'ATC-SAME-REFERENCE');
    }

    public function test_provider_manager_rejects_unsupported_network_and_limits_and_keeps_binding(): void
    {
        $this->enable('2fast');
        $manager = app(AirtimeToCashProviderManager::class);
        $this->assertSame('2fast', $manager->select('mtn', 10000)->key());
        foreach ([['glo', 500], ['mtn', 10001]] as [$network, $amount]) {
            try {
                $manager->select($network, $amount);
                $this->fail('Unsupported selection was accepted.');
            } catch (\DomainException) {
                $this->assertTrue(true);
            }
        }
        $request = new AirtimeToCashRequest(['processing_mode' => 'provider', 'provider' => '2fast']);
        AirtimeToCashProviderSetting::where('provider', '2fast')->update(['enabled' => false]);
        $this->assertSame('2fast', $manager->bound($request)->key());
    }

    public function test_success_settles_once_and_duplicate_convert_never_calls_provider_twice(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $this->automationSequence(['code' => 2000, 'data' => ['amountConverted' => 'NGN 500', 'automationCharges' => 'NGN 2']]);
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->assertSame('awaiting_otp', $request->provider_status);
        $request = $flow->verify($request->id, $user->id, '123456');
        $this->assertSame('ready_to_transfer', $request->provider_status);
        $request = $flow->convert($request->id, $user->id, '1234');
        $this->assertSame('completed', $request->provider_status);
        $this->assertSame('approved', $request->status);
        $this->assertEquals(1475, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('transactions', 1);
        $flow->convert($request->id, $user->id, '1234');
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(1475, $user->fresh()->wallet_balance);
        Http::assertSentCount(4);
    }

    public function test_pending_unknown_failed_and_low_balance_never_credit_wallet(): void
    {
        $this->enable();
        $network = $this->network();
        foreach ([
            ['code' => 4000],
            ['code' => 3000],
            ['code' => 2000, 'data' => []],
        ] as $index => $transfer) {
            $user = $this->user();
            $this->automationSequence($transfer);
            $flow = app(AirtimeToCashProviderService::class);
            $request = $flow->start($user->id, $network->id, 500, 475, '0801234567'.($index + 1), (string) Str::uuid());
            $request = $flow->verify($request->id, $user->id, '123456');
            $flow->convert($request->id, $user->id, '1234');
            $this->assertEquals(1000, $user->fresh()->wallet_balance);
            $this->assertDatabaseMissing('transactions', ['airtime_to_cash_request_id' => $request->id]);
        }
    }

    public function test_definitive_pin_rejection_can_be_corrected_without_restarting_sim_verification(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        Http::fake([
            'https://automation.airtimetocash.com/api/v1/check/quota/availability' => Http::response(['code' => 2000], 200),
            'https://automation.airtimetocash.com/api/v1/generate/otp' => Http::response(['code' => 2000], 200),
            'https://automation.airtimetocash.com/api/v1/verify/otp' => Http::response([
                'code' => 2000,
                'data' => ['sessionId' => 'safe-session-id', 'airtimeBalance' => 'NGN 1,000'],
            ], 200),
            'https://automation.airtimetocash.com/api/v1/transfer/airtime' => Http::sequence()
                ->push(['code' => 3000, 'message' => 'You have entered an invalid pin. Please double-check the pin and try again.'], 200)
                ->push(['code' => 2000, 'data' => ['amountConverted' => 'NGN 500']], 200),
        ]);
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $request = $flow->verify($request->id, $user->id, '123456');

        $request = $flow->convert($request->id, $user->id, '0000');
        $this->assertSame('ready_to_transfer', $request->provider_status);
        $this->assertSame('invalid_pin', $request->provider_message);
        $this->assertNotNull($request->provider_identifier);
        $this->assertStringContainsString('PIN was rejected', app(AirtimeToCashProviderController::class)->customerView($request)['message']);
        $this->assertEquals(1000, $user->fresh()->wallet_balance);

        $request = $flow->convert($request->id, $user->id, '1234');
        $this->assertSame('completed', $request->provider_status);
        $this->assertEquals(1475, $user->fresh()->wallet_balance);
    }

    public function test_low_verified_airtime_balance_blocks_transfer_call(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $this->automationSequence(['code' => 2000, 'data' => ['amountConverted' => 'NGN 500']], [
            'code' => 2000,
            'data' => ['sessionId' => 'safe-session-id', 'airtimeBalance' => 'NGN 100'],
        ]);
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $request = $flow->verify($request->id, $user->id, '123456');
        try {
            $flow->convert($request->id, $user->id, '1234');
            $this->fail('Low balance was accepted.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('too low', $e->getMessage());
        }
        Http::assertSentCount(3);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_idempotent_start_and_immutable_binding_pricing_and_reference(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $this->automationSequence(['code' => 4000]);
        $key = (string) Str::uuid();
        $flow = app(AirtimeToCashProviderService::class);
        $first = $flow->start($user->id, $network->id, 500, 475, '08012345678', $key);
        $second = $flow->start($user->id, $network->id, 500, 475, '08012345678', $key);
        $this->assertSame($first->id, $second->id);
        Http::assertSentCount(2);
        foreach (['provider' => '2fast', 'provider_reference' => 'changed', 'amount' => 600, 'payout_amount' => 400] as $field => $value) {
            $copy = $first->fresh();
            $copy->{$field} = $value;
            try {
                $copy->save();
                $this->fail("Immutable field {$field} was changed.");
            } catch (\DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_pin_otp_and_token_are_absent_from_storage_audit_logs_and_responses(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $this->automationSequence(['code' => 3000, 'message' => 'invalid pin']);
        Log::spy();
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $flow->verify($request->id, $user->id, '654321');
        $flow->convert($request->id, $user->id, '9876');
        $json = json_encode(AirtimeToCashRequest::findOrFail($request->id)->getAttributes());
        $this->assertStringNotContainsString('9876', $json);
        $this->assertStringNotContainsString('654321', $json);
        $this->assertStringNotContainsString('test-automation-token', $json);
        $this->assertStringNotContainsString('safe-session-id', json_encode(AirtimeToCashRequest::findOrFail($request->id)));
        $this->assertDatabaseMissing('audit_logs', ['description' => '1234']);
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('error');
    }

    public function test_owner_scope_https_and_secret_sanitization_on_endpoints(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $other = $this->user();
        $this->automationSequence(['code' => 4000]);
        $request = app(AirtimeToCashProviderService::class)->start(
            $user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid()
        );
        $this->actingAs($other)->postJson("/api/customer/airtime-to-cash/{$request->id}/verify-otp", ['otp' => '123456'])
            ->assertStatus(400)->assertJsonMissing(['otp' => '123456']);
        $this->withHeader('X-Forwarded-Proto', 'https')->actingAs($other)
            ->postJson("/api/customer/airtime-to-cash/{$request->id}/verify-otp", ['otp' => '123456'])
            ->assertNotFound()->assertJsonMissing(['otp' => '123456']);
        $this->assertSame('awaiting_otp', $request->fresh()->provider_status);
    }

    public function test_reconciliation_uses_same_reference_and_unknown_never_credits(): void
    {
        $this->enable('2fast');
        $network = $this->network();
        $user = $this->user();
        Http::fake([
            'https://2fast.com.ng/api/Airtime-To-Cash' => Http::sequence()
                ->push(['status' => 'success', 'skip_otp' => true, 'identifier' => 'identifier'])
                ->push(['status' => 'success', 'message' => 'Awaiting airtime confirmation. You will be credited once received.']),
            'https://2fast.com.ng/api/transaction-history' => Http::response(['status' => 'error', 'message' => 'Transaction not found.'], 200),
        ]);
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $request = $flow->convert($request->id, $user->id, '1234');
        $this->assertSame('provider_pending', $request->provider_status);
        $request->last_provider_check_at = now()->subMinutes(2);
        $request->save();
        app(AirtimeToCashReconciliationService::class)->reconcile($request->id);
        $this->assertEquals(1000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('transactions', 0);
        Http::assertSent(fn (ClientRequest $http) => str_ends_with($http->url(), '/transaction-history')
            && $http['reference'] === $request->provider_reference);
    }

    public function test_terminal_failure_is_persisted_and_consistent_in_both_apis_and_history(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $this->automationSequence(['code' => 3000, 'message' => 'Private upstream failure token=secret']);
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $flow->verify($request->id, $user->id, '123456');
        $failed = $flow->convert($request->id, $user->id, '1234');
        $this->assertSame('failed', $failed->status);
        $this->assertSame('failed', $failed->provider_status);
        $this->assertSame($request->transaction_reference, $failed->transaction_reference);
        $this->assertSame('failed', $failed->provider_message);
        $this->assertNull($failed->provider_identifier);
        $this->assertNull($failed->active_session_key);
        $this->assertEquals(1000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0, AirtimeToCashRequest::where('status', 'pending')->count());

        // Exercise the real serialization endpoints; authorization is covered
        // separately by the permission tests.
        $this->withoutMiddleware()->actingAs($user);
        $this->getJson("/api/customer/airtime-to-cash/{$request->id}/status")
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.state', 'failed')
            ->assertJsonPath('data.message', 'Conversion failed. Your wallet has not been credited.');
        $this->getJson('/api/customer/airtime-to-cash')->assertOk()->assertJsonPath('data.0.status', 'failed');
        $this->getJson('/api/admin/airtime-to-cash')->assertOk()->assertJsonPath('data.0.status', 'failed');

        foreach (['failed', 'failed', 'success'] as $state) {
            $this->assertSame('failed', $flow->recordResult($request->id, new ProviderResult($state))->status);
        }
        $flow->convert($request->id, $user->id, '1234');
        Http::assertSentCount(4);
        try {
            app(AirtimeToCashSettlementService::class)->settle($request->id);
            $this->fail('Failed request was paid.');
        } catch (\DomainException) {
            $this->assertDatabaseCount('transactions', 0);
        }
        $this->assertEquals(1000, $user->fresh()->wallet_balance);
    }

    public function test_direct_auth_rejection_fails_but_transport_uncertainty_remains_pending(): void
    {
        $this->enable();
        $network = $this->network();
        foreach ([401 => 'failed', 403 => 'failed', 500 => 'pending', 429 => 'pending', 0 => 'pending'] as $http => $status) {
            Http::swap(new Factory);
            $user = $this->user();
            Http::fake([
                'https://automation.airtimetocash.com/api/v1/check/quota/availability' => Http::response(['code' => 2000]),
                'https://automation.airtimetocash.com/api/v1/generate/otp' => Http::response(['code' => 2000]),
                'https://automation.airtimetocash.com/api/v1/verify/otp' => Http::response(['code' => 2000, 'data' => ['sessionId' => 'session']]),
                'https://automation.airtimetocash.com/api/v1/transfer/airtime' => $http === 0
                    ? Http::failedConnection() : Http::response([], $http),
            ]);
            $flow = app(AirtimeToCashProviderService::class);
            $request = $flow->start($user->id, $network->id, 500, 475, '0801234'.str_pad((string) $http, 4, '0', STR_PAD_LEFT), (string) Str::uuid());
            $flow->verify($request->id, $user->id, '123456');
            $request = $flow->convert($request->id, $user->id, '1234');
            $this->assertSame($status, $request->status);
            $this->assertSame($status === 'failed' ? 'failed' : 'provider_pending', $request->provider_status);
            $this->assertEquals(1000, $user->fresh()->wallet_balance);
        }
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_quota_timeout_does_not_become_a_terminal_failure(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        Http::fake(['*' => Http::failedConnection()]);
        $request = app(AirtimeToCashProviderService::class)->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->assertSame('pending', $request->status);
        $this->assertSame('manual_review', $request->provider_status);
        Http::assertSentCount(1);
    }

    public function test_twofast_direct_and_lookup_failures_persist_failed_without_paying(): void
    {
        $this->enable('2fast');
        $network = $this->network();
        foreach ([false, true] as $lookup) {
            Http::swap(new Factory);
            $user = $this->user();
            Http::fake([
                'https://2fast.com.ng/api/Airtime-To-Cash' => Http::sequence()
                    ->push(['status' => 'success', 'skip_otp' => true, 'identifier' => 'id'])
                    ->push($lookup ? ['status' => 'success', 'message' => 'Awaiting airtime confirmation.'] : ['status' => 'error']),
                'https://2fast.com.ng/api/transaction-history' => fn (ClientRequest $http) => Http::response([
                    'status' => 'success', 'source' => 'wallet_history',
                    'data' => ['reference' => $http['reference'], 'type' => 'Airtime To Cash', 'status' => 'Failed'],
                ]),
            ]);
            $flow = app(AirtimeToCashProviderService::class);
            $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
            $request = $flow->convert($request->id, $user->id, '1234');
            if ($lookup) {
                $this->assertSame('pending', $request->status);
                $request->update(['last_provider_check_at' => now()->subMinutes(2)]);
                $request = app(AirtimeToCashReconciliationService::class)->reconcile($request->id);
            }
            $this->assertSame('failed', $request->status);
            $this->assertSame('failed', $request->provider_status);
            $this->assertEquals(1000, $user->fresh()->wallet_balance);
        }
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_backfill_only_repairs_proven_unpaid_terminal_provider_failures(): void
    {
        $user = $this->user();
        $ids = [];
        foreach ([
            ['provider', 'failed', null, null],
            ['manual', 'failed', null, null],
            ['provider', 'provider_pending', null, null],
            ['provider', 'manual_review', null, null],
            ['provider', 'expired', null, null],
            ['provider', 'failed', 'existing-payout', null],
            ['provider', 'failed', null, now()],
        ] as [$mode, $state, $payout, $confirmed]) {
            // SQL recreates historical inconsistent rows, bypassing the new invariant.
            $ids[] = DB::table('airtime_to_cash_requests')->insertGetId([
                'user_id' => $user->id, 'network' => 'mtn', 'sender_phone' => '08012345678',
                'destination_number' => '', 'amount' => 100, 'payout_amount' => 95,
                'transaction_reference' => 'ATC-'.Str::uuid(), 'status' => 'pending',
                'processing_mode' => $mode, 'provider_status' => $state,
                'payout_transaction_reference' => $payout, 'provider_confirmed_at' => $confirmed,
                'provider_message' => 'failed',
            ]);
        }
        $migration = require database_path('migrations/2026_09_11_000000_persist_failed_airtime_to_cash_status.php');
        $migration->up();
        $migration->up();
        foreach ($ids as $index => $id) {
            $this->assertSame($index === 0 ? 'failed' : 'pending', AirtimeToCashRequest::findOrFail($id)->status);
        }
        $this->assertDatabaseCount('transactions', 0);
        $this->assertEquals(1000, $user->fresh()->wallet_balance);
    }

    public function test_twofast_pending_lookup_success_credits_once_and_cannot_later_fail(): void
    {
        $this->enable('2fast');
        $network = $this->network();
        $user = $this->user();
        Http::fake([
            'https://2fast.com.ng/api/Airtime-To-Cash' => Http::sequence()
                ->push(['status' => 'success', 'skip_otp' => true, 'identifier' => 'id'])
                ->push(['status' => 'success', 'message' => 'Awaiting airtime confirmation.']),
            'https://2fast.com.ng/api/transaction-history' => fn (ClientRequest $http) => Http::response([
                'status' => 'success', 'source' => 'wallet_history',
                'data' => ['reference' => $http['reference'], 'type' => 'Airtime To Cash', 'status' => 'Successful'],
            ]),
        ]);
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $request = $flow->convert($request->id, $user->id, '1234');
        $request->update(['last_provider_check_at' => now()->subMinutes(2)]);
        $reconcile = app(AirtimeToCashReconciliationService::class);
        $completed = $reconcile->reconcile($request->id);
        $this->assertSame('approved', $completed->status);
        $this->assertSame('completed', $completed->provider_status);
        $reconcile->reconcile($request->id);
        $this->assertSame('approved', $flow->recordResult($request->id, new ProviderResult('failed'))->status);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(1475, $user->fresh()->wallet_balance);
        Http::assertSentCount(3);

        // A corrupt legacy row with a linked payout is excluded from repair.
        DB::table('airtime_to_cash_requests')->where('id', $request->id)->update([
            'status' => 'pending', 'provider_status' => 'failed',
            'payout_transaction_reference' => null, 'provider_confirmed_at' => null,
        ]);
        $migration = require database_path('migrations/2026_09_11_000000_persist_failed_airtime_to_cash_status.php');
        $migration->up();
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(1475, $user->fresh()->wallet_balance);
    }

    public function test_automation_documented_endpoints_headers_and_payloads_match_the_wire_contract(): void
    {
        Http::fake(['https://automation.airtimetocash.com/*' => Http::response([
            'code' => 2000, 'data' => ['sessionId' => 'session', 'amountConverted' => '₦50', 'airtimeBalance' => '₦220,751.8', 'automationCharges' => '₦2'],
        ])]);
        $provider = app(AutomationProvider::class);
        $provider->requestOtp('mtn', '08012345678');
        $verified = $provider->verifyOtp('mtn', '08012345678', '123456');
        $this->assertSame(220751.8, $verified->balance);
        $provider->checkSession('mtn', '08012345678', 'session');
        $provider->checkQuota('mtn', 50);
        $reference = 'ATC-'.Str::uuid();
        $this->assertSame(40, strlen($reference));
        $converted = $provider->convert('mtn', '08012345678', 50, $reference, 'session', '1234');
        $this->assertSame(50.0, $converted->convertedAmount);
        $this->assertSame(2.0, $converted->fee);
        $expected = [
            'generate/otp' => ['networkName' => 'MTN', 'sender' => '08012345678'],
            'verify/otp' => ['networkName' => 'MTN', 'sender' => '08012345678', 'otp' => '123456'],
            'login/with/session/id' => ['networkName' => 'MTN', 'sender' => '08012345678', 'sessionId' => 'session'],
            'check/quota/availability' => ['networkName' => 'MTN', 'amount' => 50],
            'transfer/airtime' => ['networkName' => 'MTN', 'sender' => '08012345678', 'amount' => 50, 'reference' => $reference, 'sessionId' => 'session', 'pin' => '1234'],
        ];
        foreach ($expected as $path => $payload) {
            Http::assertSent(function (ClientRequest $request) use ($path, $payload) {
                if ($request->url() !== 'https://automation.airtimetocash.com/api/v1/'.$path) {
                    return false;
                }
                $this->assertSame('POST', $request->method());
                $this->assertSame($payload, json_decode($request->body(), true));
                $this->assertTrue($request->hasHeader('Accept', 'application/json'));
                $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
                if (in_array($path, ['generate/otp', 'verify/otp'], true)) {
                    $this->assertFalse($request->hasHeader('Authorization'));
                } else {
                    $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-automation-token'));
                }

                return true;
            });
        }
        Http::assertSentCount(5);
    }

    public function test_automation_selection_enforces_all_documented_network_amount_boundaries(): void
    {
        $this->enable();
        $manager = app(AirtimeToCashProviderManager::class);
        foreach (['mtn' => 10000, 'airtel' => 20000, 'glo' => 1000, '9mobile' => 20000] as $network => $maximum) {
            foreach ([50, $maximum] as $amount) {
                $this->assertSame('airtime_to_cash_automation', $manager->select($network, $amount)->key());
            }
            foreach ([49, $maximum + 1, 50.5] as $amount) {
                try {
                    $manager->select($network, $amount);
                    $this->fail('Out-of-contract amount accepted.');
                } catch (\DomainException) {
                    $this->assertTrue(true);
                }
            }
        }
        Http::fake();
        Http::assertNothingSent();
    }
}
