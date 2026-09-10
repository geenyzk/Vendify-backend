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
use App\Services\AirtimeToCash\Providers\AutomationProvider;
use App\Services\AirtimeToCash\Providers\TwoFastProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
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
            'https://automation.airtimetocash.com/api/v1/check/quota/availability' => Http::response(['code' => 2000], 200),
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

    public function test_automation_quota_5030_is_always_unavailable_until_contract_is_clarified(): void
    {
        $provider = app(AutomationProvider::class);
        foreach (['Recipient(s) Available', 'Unavailability of recipient!'] as $message) {
            $this->assertSame('unavailable', $provider->normalize('quota', 200, ['code' => 5030, 'message' => $message])->state);
        }
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
}
