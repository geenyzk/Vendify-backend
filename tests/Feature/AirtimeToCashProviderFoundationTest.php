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
use App\Services\AirtimeToCashAvailabilityService;
use App\Services\AirtimeToCashSettlementService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AirtimeToCashProviderFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'airtime_to_cash.provider_mode_enabled' => true,
            'airtime_to_cash.live_calls_enabled' => true,
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
            'airtime_to_cash_automated_active' => true,
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

    public function test_production_quota_trace_with_recipients_available_continues_to_otp_then_pin_and_credits_once(): void
    {
        // Mirrors ATC-e8029664-a34e-4912-b2de-1c6f811ed1d1: quota HTTP 200 with provider_code "5030".
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $logs = [];
        Log::shouldReceive('info')->andReturnUsing(function ($event, $context) use (&$logs) {
            $logs[] = ['event' => $event, ...$context];
        });
        Http::fake([
            'https://automation.airtimetocash.com/api/v1/check/quota/availability' => Http::response(['code' => '5030', 'message' => 'Recipient(s) Available'], 200),
            'https://automation.airtimetocash.com/api/v1/generate/otp' => Http::response(['code' => 2000], 200),
            'https://automation.airtimetocash.com/api/v1/verify/otp' => Http::response(['code' => 2000, 'data' => ['sessionId' => 'safe-session-id', 'airtimeBalance' => '₦1,000']], 200),
            'https://automation.airtimetocash.com/api/v1/transfer/airtime' => Http::response(['code' => 2000, 'data' => ['amountConverted' => '₦500', 'automationCharges' => '₦2']], 200),
        ]);
        $flow = app(AirtimeToCashProviderService::class);

        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->assertSame('pending', $request->status);
        $this->assertSame('awaiting_otp', $request->provider_status);
        $this->assertFalse($request->lifecycle['transfer_submitted']);
        $this->assertSame(['/api/v1/check/quota/availability', '/api/v1/generate/otp'],
            Http::recorded()->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->all());
        $quota = collect($logs)->firstWhere('operation', 'quota');
        $this->assertSame(['5030', 200, true, 'recipients_available', 'message', false], [$quota['provider_code'], $quota['http_status'],
            $quota['succeeded'], $quota['semantic_outcome'], $quota['message_field'], $quota['transfer_submitted']]);
        $this->assertSame([[null, 'created'], ['created', 'awaiting_otp']], collect($logs)->where('event', 'airtime_to_cash.lifecycle')
            ->map(fn ($log) => [$log['from'], $log['to']])->values()->all());
        $this->assertDatabaseCount('transactions', 0);

        $request = $flow->verify($request->id, $user->id, '123456');
        $this->assertSame('ready_to_transfer', $request->provider_status);
        Http::assertSentCount(3);
        $this->assertSame('approved', $flow->convert($request->id, $user->id, '1234')->status);
        $flow->convert($request->id, $user->id, '1234');
        Http::assertSentCount(4);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(1475, $user->fresh()->wallet_balance);
    }

    public function test_quota_5030_tolerates_harmless_formatting_but_only_for_quota(): void
    {
        $provider = app(AutomationProvider::class);
        foreach ([
            ['code' => '5030', 'message' => 'Recipient(s) Available'],
            ['code' => 5030, 'message' => '  recipient(s) available  '],
            ['code' => 5030, 'message' => "RECIPIENT(S)\u{00A0}AVAILABLE."],
            ['code' => 5030, 'message' => "Recipient(s)\tAvailable!"],
            ['code' => 5030, 'message' => 'Recipients Available'],
            ['code' => 5030, 'message' => ['Recipient(s) Available']],
            ['code' => 5030, 'data' => ['message' => 'Recipient(s) Available']],
        ] as $body) {
            $result = $provider->normalize('quota', 200, $body);
            $this->assertSame(['success', 'recipients_available'], [$result->state, $result->semantic], json_encode($body));
            foreach (['otp', 'verify', 'session', 'convert'] as $operation) {
                $other = $provider->normalize($operation, 200, $body);
                $this->assertSame(['unavailable', null], [$other->state, $other->semantic], $operation);
            }
            $this->assertSame('unknown', $provider->normalize('quota', 500, $body)->state);
            $this->assertSame('failed', $provider->normalize('quota', 422, $body)->state);
        }
        foreach ([
            'recipients_unavailable' => ['Unavailability of recipient!', 'Service/Recipient is unavailable at the moment',
                'Recipient(s) Unavailable', 'Not Recipient(s) Available', 'No Recipient(s) Available'],
            'unrecognised_5030' => ['', 5030, 'Recipient(s) Available but transfer pending', 'Recipient Available',
                'Recipient(s) Avail able', ['Recipient(s) Available', 'Pending']],
        ] as $semantic => $messages) {
            foreach ($messages as $message) {
                $result = $provider->normalize('quota', 200, ['code' => 5030, 'message' => $message]);
                $this->assertSame(['unavailable', $semantic], [$result->state, $result->semantic], json_encode($message));
            }
        }
        $missing = $provider->normalize('quota', 200, ['code' => 5030]);
        $this->assertSame(['unavailable', 'unrecognised_5030', 'none'], [$missing->state, $missing->semantic, $missing->messageField]);
        // The documented top-level field wins over a nested fallback.
        $conflict = $provider->normalize('quota', 200, ['code' => 5030, 'message' => 'Service unavailable', 'data' => ['message' => 'Recipient(s) Available']]);
        $this->assertSame(['unavailable', 'recipients_unavailable', 'message'], [$conflict->state, $conflict->semantic, $conflict->messageField]);
    }

    public function test_unrelated_quota_5030_fails_before_otp_and_logs_only_a_safe_classification(): void
    {
        $this->enable();
        $network = $this->network();
        $logs = [];
        Log::shouldReceive('info')->andReturnUsing(function ($event, $context) use (&$logs) {
            $logs[] = ['event' => $event, ...$context];
        });
        $cases = [
            ['Recipient 08012345678 unavailable, token test-automation-token', 'recipients_unavailable', ['recipient', 'unavailable']],
            ['Recipient(s) Available but transfer pending', 'unrecognised_5030', ['available', 'recipient', 's']],
        ];
        Http::fake(['https://automation.airtimetocash.com/api/v1/check/quota/availability' => Http::sequence()
            ->push(['code' => 5030, 'message' => $cases[0][0]], 200)->push(['code' => 5030, 'message' => $cases[1][0]], 200)]);
        foreach ($cases as $index => [$message, $semantic, $terms]) {
            $logs = [];
            $request = app(AirtimeToCashProviderService::class)->start($this->user()->id, $network->id, 500, 475, "0801234567{$index}", (string) Str::uuid());
            $this->assertSame(['failed', false], [$request->status, $request->lifecycle['transfer_submitted']]);
            Http::assertNotSent(fn (ClientRequest $sent) => ! str_ends_with($sent->url(), '/check/quota/availability'));
            $quota = collect($logs)->firstWhere('operation', 'quota');
            $this->assertSame(['5030', false, 'unavailable', $semantic, $terms], [$quota['provider_code'], $quota['succeeded'],
                $quota['sanitized_message'], $quota['semantic_outcome'], $quota['message_terms']]);
            foreach (['08012345678', "0801234567{$index}", 'test-automation-token', 'but transfer pending', 'Recipient'] as $prose) {
                $this->assertStringNotContainsString($prose, json_encode($logs));
            }
        }
    }

    private function automatedOnlyNetwork(string $name): Network
    {
        // The reported admin state: manual method disabled, no destination, manual limits narrower than the provider's.
        $network = Network::create(['name' => $name, 'active' => true, 'airtime_to_cash_active' => false,
            'airtime_to_cash_automated_active' => true, 'airtime_to_cash_destination_number' => null,
            'airtime_to_cash_min' => 100, 'airtime_to_cash_max' => 500]);
        Discount::create(['name' => "$name Airtime to Cash", 'service_type' => 'airtimeToCash',
            'network' => AirtimeToCashAvailabilityService::canonicalName($name), 'discount_type' => 'percentage', 'value' => 5, 'active' => true]);

        return $network;
    }

    public function test_automated_networks_do_not_depend_on_manual_toggle_destination_or_limits(): void
    {
        Http::fake();
        $this->enable();
        $this->network();
        $maximums = ['AIRTEL' => 20000, 'GLO' => 1000, '9MOBILE' => 20000];
        $networks = collect($maximums)->map(fn ($maximum, $name) => $this->automatedOnlyNetwork($name));
        $this->actingAs($this->user());
        $catalog = collect($this->getJson('/api/customer/airtime-to-cash/networks')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame([true, true], [$catalog['MTN']['available'], $catalog['MTN']['automated_available']]);
        foreach ($maximums as $name => $maximum) {
            $this->assertSame([false, true, null], [$catalog[$name]['available'], $catalog[$name]['automated_available'], $catalog[$name]['automated_reason']], $name);
            $this->assertStringContainsString('disabled', $catalog[$name]['reason']);
            $id = $networks[$name]->id;
            $this->getJson("/api/customer/airtime-to-cash/provider/options?network_id=$id")->assertOk()
                ->assertJsonPath('data.automated_available', true)->assertJsonPath('data.manual_available', false);
            $this->assertEquals($maximum * 0.95, $this->getJson("https://localhost/api/customer/airtime-to-cash/provider/quote?network_id=$id&amount=$maximum")
                ->assertOk()->json('data.payout_amount'));
            $this->getJson("https://localhost/api/customer/airtime-to-cash/provider/quote?network_id=$id&amount=".($maximum + 1))->assertUnprocessable();
            // Manual conversion still requires its own configuration.
            $this->postJson('/api/customer/airtime-to-cash', ['network_id' => $id, 'amount' => 200, 'sender_phone' => '08012345678'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('airtime_to_cash_requests', 0);
        Http::assertNothingSent();
    }

    public function test_automated_airtel_starts_without_a_manual_destination(): void
    {
        $this->enable();
        $airtel = $this->automatedOnlyNetwork('AIRTEL');
        Http::fake([
            'https://automation.airtimetocash.com/api/v1/check/quota/availability' => Http::response(['code' => 5030, 'message' => 'Recipient(s) Available'], 200),
            'https://automation.airtimetocash.com/api/v1/generate/otp' => Http::response(['code' => 2000], 200),
        ]);
        $request = app(AirtimeToCashProviderService::class)->start($this->user()->id, $airtel->id, 15000, 14250, '08012345678', (string) Str::uuid());
        $this->assertSame(['pending', 'awaiting_otp', ''], [$request->status, $request->provider_status, $request->destination_number]);
        Http::assertSent(fn (ClientRequest $sent) => str_ends_with($sent->url(), '/check/quota/availability')
            && $sent['networkName'] === 'AIRTEL' && $sent['amount'] == 15000);
        Http::assertSentCount(2);
    }

    public function test_automated_network_availability_still_requires_provider_support_rate_and_runtime(): void
    {
        Http::fake();
        foreach (['AIRTEL', 'GLO', 'SMILE'] as $name) {
            $this->automatedOnlyNetwork($name); // SMILE is outside the provider contract.
        }
        $this->actingAs($this->user());
        $automated = fn () => collect($this->getJson('/api/customer/airtime-to-cash/networks')->assertOk()->json('data'))
            ->pluck('automated_available', 'name')->all();
        $this->assertSame(['AIRTEL' => false, 'GLO' => false, 'SMILE' => false], $automated());
        $this->enable('2fast'); // 2FAST documents MTN and Airtel only.
        $this->assertSame(['AIRTEL' => true, 'GLO' => false, 'SMILE' => false], $automated());
        $this->enable();
        $this->assertSame(['AIRTEL' => true, 'GLO' => true, 'SMILE' => false], $automated());
        Discount::where('network', 'glo')->update(['active' => false]);
        $this->assertSame(['AIRTEL' => true, 'GLO' => false, 'SMILE' => false], $automated());
        config(['airtime_to_cash.live_calls_enabled' => false]);
        $this->assertSame(['AIRTEL' => false, 'GLO' => false, 'SMILE' => false], $automated());
        Http::assertNothingSent();
    }

    public static function enablementCombinations(): array
    {
        return ['both enabled' => [true, true], 'manual only' => [true, false],
            'automated only' => [false, true], 'both disabled' => [false, false]];
    }

    #[DataProvider('enablementCombinations')]
    public function test_manual_and_automated_enablement_are_independent_per_network(bool $manual, bool $automated): void
    {
        Http::fake();
        $this->enable();
        $network = $this->network('AIRTEL');
        $network->update(['airtime_to_cash_active' => $manual, 'airtime_to_cash_automated_active' => $automated]);
        $this->actingAs($this->user());
        $entry = collect($this->getJson('/api/customer/airtime-to-cash/networks')->assertOk()->json('data'))->firstWhere('id', $network->id);
        $this->assertSame([$manual, $automated], [$entry['available'], $entry['automated_available']]);
        $this->assertSame($automated ? null : 'Automated conversion is disabled for this network.', $entry['automated_reason']);
        $this->assertSame($manual ? null : 'Airtime to cash is disabled for this network.', $entry['reason']);
        $this->getJson("/api/customer/airtime-to-cash/provider/options?network_id={$network->id}")->assertOk()
            ->assertJsonPath('data.manual_available', $manual)->assertJsonPath('data.automated_available', $automated);
        $this->getJson("https://localhost/api/customer/airtime-to-cash/provider/quote?network_id={$network->id}&amount=500")->assertStatus($automated ? 200 : 422);
        $this->getJson("/api/vtu/airtimeToCash/discount?network_id={$network->id}&amount=500")->assertStatus($manual ? 200 : 422);
        $this->postJson('/api/customer/airtime-to-cash', ['network_id' => $network->id, 'amount' => 500, 'sender_phone' => '08012345678'])
            ->assertStatus($manual ? 201 : 422);
        Http::assertNothingSent();
    }

    public function test_provider_priority_still_selects_among_providers_for_an_automated_network(): void
    {
        $network = $this->network('AIRTEL'); // Both adapters document Airtel.
        AirtimeToCashProviderSetting::create(['provider' => '2fast', 'enabled' => true, 'priority' => 1]);
        AirtimeToCashProviderSetting::create(['provider' => 'airtime_to_cash_automation', 'enabled' => true, 'priority' => 2]);
        $manager = app(AirtimeToCashProviderManager::class);
        $flow = app(AirtimeToCashProviderService::class);
        $this->assertSame('2fast', $manager->select('airtel', 500)->key());
        $this->assertTrue($flow->quote($network->id, 500)['available']);
        AirtimeToCashProviderSetting::where('provider', '2fast')->update(['priority' => 3]);
        $this->assertSame('airtime_to_cash_automation', $manager->select('airtel', 500)->key());
        $network->update(['airtime_to_cash_automated_active' => false]);
        try {
            $flow->quote($network->id, 500);
            $this->fail('A network with automation switched off was quoted.');
        } catch (\DomainException $e) {
            $this->assertSame('Automated conversion is disabled for this network.', $e->getMessage());
        }
    }

    public function test_manual_availability_still_requires_its_own_destination_when_automation_is_available(): void
    {
        Http::fake();
        $this->enable();
        $network = $this->network();
        $network->update(['airtime_to_cash_destination_number' => null]);
        $this->actingAs($this->user());
        $this->getJson('/api/customer/airtime-to-cash/provider/options')->assertOk()
            ->assertJsonPath('data.automated_available', true)->assertJsonPath('data.manual_available', false);
        $entry = $this->getJson('/api/customer/airtime-to-cash/networks')->assertOk()->json('data.0');
        $this->assertSame([false, true], [$entry['available'], $entry['automated_available']]);
        $this->assertStringContainsString('destination', $entry['reason']);
        $this->getJson('/api/vtu/airtimeToCash/discount?network_id='.$network->id.'&amount=500')->assertUnprocessable();
        $this->postJson('/api/customer/airtime-to-cash', ['network_id' => $network->id, 'amount' => 500, 'sender_phone' => '08012345678'])->assertUnprocessable();
        $this->assertDatabaseCount('airtime_to_cash_requests', 0);
        Http::assertNothingSent();
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
        Log::shouldHaveReceived('info')->withArgs(fn ($event, $context) => $event === 'airtime_to_cash.provider_call'
            && ! array_intersect(['pin', 'otp', 'sessionId', 'token', 'authorization'], array_keys($context)))->atLeast()->once();
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
        $this->assertSame('setup_review', $request->provider_status);
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

    public function test_new_start_key_returns_owned_active_conversion_without_creating_another_session(): void
    {
        $this->enable();
        $user = $this->user();
        $network = $this->network();
        $this->automationSequence(['code' => 2000]);
        $flow = app(AirtimeToCashProviderService::class);
        $first = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $again = $flow->start($user->id, $network->id, 1000, 950, '08012345678', (string) Str::uuid());
        $this->assertSame($first->id, $again->id);
        $this->assertSame('awaiting_otp', $again->provider_status);
        $this->assertEquals(500, $again->amount);
        $this->assertTrue($again->resumed);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('airtime_to_cash_requests', 1);
    }

    public function test_resume_restores_otp_pin_and_processing_without_transfer_or_credit(): void
    {
        $this->enable();
        $user = $this->user();
        $network = $this->network();
        $this->automationSequence(['code' => 4000]);
        Http::fake(['*/login/with/session/id' => Http::response(['code' => 2000, 'data' => ['sessionId' => 'safe-session-id']], 200)]);
        $flow = app(AirtimeToCashProviderService::class);
        $first = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->assertSame('awaiting_otp', $flow->resume($first->id, $user->id)->provider_status);
        $flow->verify($first->id, $user->id, '123456');
        $this->assertSame('ready_to_transfer', $flow->resume($first->id, $user->id)->provider_status);
        $flow->convert($first->id, $user->id, '1234');
        $this->travel(20)->minutes();
        $this->assertSame('provider_pending', $flow->resume($first->id, $user->id)->provider_status);
        $this->assertNotNull($first->fresh()->active_session_key);
        $flow->convert($first->id, $user->id, '1234');
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/transfer/airtime')));
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_local_expiry_releases_sim_but_never_reopens_old_reference(): void
    {
        $this->enable();
        $user = $this->user();
        $network = $this->network();
        $this->automationSequence(['code' => 2000]);
        $flow = app(AirtimeToCashProviderService::class);
        $old = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->travel(20)->minutes();
        $this->assertSame('expired', $flow->resume($old->id, $user->id)->provider_status);
        $new = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->assertNotSame($old->id, $new->id);
        $this->assertNull($old->fresh()->active_session_key);
        $this->expectException(\DomainException::class);
        $flow->restartOtp($old->id, $user->id);
    }

    public function test_provider_expiry_and_unknown_session_check_are_distinguished(): void
    {
        $this->enable();
        $user = $this->user();
        $network = $this->network();
        $this->automationSequence(['code' => 2000]);
        Http::fake(['*/login/with/session/id' => Http::sequence()->push(['code' => 5000], 500)->push(['code' => 4010], 200)]);
        $flow = app(AirtimeToCashProviderService::class);
        $old = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $flow->verify($old->id, $user->id, '123456');
        $this->assertSame('ready_to_transfer', $flow->resume($old->id, $user->id)->provider_status);
        $this->assertNotNull($old->fresh()->active_session_key);
        $this->assertSame('expired', $flow->resume($old->id, $user->id)->provider_status);
        $this->assertNull($old->fresh()->provider_identifier);
        $this->assertNotSame($old->id, $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid())->id);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/transfer/airtime'));
    }

    public function test_legacy_terminal_reservations_do_not_block_or_reopen(): void
    {
        $this->enable();
        $user = $this->user();
        $network = $this->network();
        $this->automationSequence(['code' => 2000]);
        $flow = app(AirtimeToCashProviderService::class);
        foreach ([['pending', 'failed'], ['approved', 'completed'], ['rejected', 'awaiting_otp'], ['pending', 'cancelled'], ['pending', 'session_expired']] as $i => [$status, $state]) {
            $phone = '0801234567'.$i;
            $old = $flow->start($user->id, $network->id, 500, 475, $phone, (string) Str::uuid());
            // Simulate historical rows, bypassing current saving guards.
            DB::table('airtime_to_cash_requests')->where('id', $old->id)->update(['status' => $status, 'provider_status' => $state]);
            $new = $flow->start($user->id, $network->id, 500, 475, $phone, (string) Str::uuid());
            $this->assertNotSame($old->id, $new->id);
            $this->assertNull($old->fresh()->active_session_key);
            $this->assertSame($state, $flow->resume($old->id, $user->id)->provider_status);
            $flow->convert($old->id, $user->id, '1234');
        }
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/transfer/airtime'));
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_active_and_resume_endpoints_are_owner_scoped_and_hide_secrets(): void
    {
        $this->enable();
        $user = $this->user();
        $other = $this->user();
        $network = $this->network();
        $this->automationSequence(['code' => 2000]);
        $flow = app(AirtimeToCashProviderService::class);
        $old = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->withoutMiddleware()->actingAs($other);
        $this->getJson('/api/customer/airtime-to-cash/provider/active')->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/customer/airtime-to-cash/'.$old->id.'/resume')->assertNotFound();
        $this->actingAs($user);
        $response = $this->postJson('/api/customer/airtime-to-cash/'.$old->id.'/resume')->assertOk()->assertJsonPath('data.id', $old->id)->assertJsonPath('data.resumed', true);
        $this->assertArrayNotHasKey('provider_identifier', $response->json('data'));
        $this->assertArrayNotHasKey('active_session_key', $response->json('data'));
        $this->expectException(\DomainException::class);
        $flow->start($other->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
    }

    public function test_resume_completed_conversion_never_credits_twice(): void
    {
        $this->enable();
        $user = $this->user();
        $network = $this->network();
        $this->automationSequence(['code' => 2000, 'data' => ['amountConverted' => 500]]);
        $flow = app(AirtimeToCashProviderService::class);
        $old = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $flow->verify($old->id, $user->id, '123456');
        $flow->convert($old->id, $user->id, '1234');
        $this->assertSame('approved', $flow->resume($old->id, $user->id)->status);
        $flow->resume($old->id, $user->id);
        $flow->convert($old->id, $user->id, '1234');
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(1475, $user->fresh()->wallet_balance);
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/transfer/airtime')));
    }

    public function test_twofast_resume_reconciles_unknown_then_success_without_repeating_transfer(): void
    {
        $this->enable('2fast');
        $user = $this->user();
        $network = $this->network();
        Http::fakeSequence('https://2fast.com.ng/api/Airtime-To-Cash')
            ->push(['status' => 'success', 'skip_otp' => true, 'identifier' => 'twofast-session', 'airtime_balance' => 1000])
            ->push(['status' => 'success', 'message' => 'Awaiting airtime confirmation. You will be credited once received.']);
        $flow = app(AirtimeToCashProviderService::class);
        $old = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->assertSame('ready_to_transfer', $flow->resume($old->id, $user->id)->provider_status);
        Http::assertSentCount(1);
        $flow->convert($old->id, $user->id, '1234');
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/Airtime-To-Cash') && (int) $r['step'] === 3));
        Http::fake(['*/transaction-history' => Http::sequence()
            ->push(['status' => 'error', 'message' => 'Not found'])
            ->push(['status' => 'success', 'source' => 'wallet_history', 'data' => [
                'reference' => $old->provider_reference, 'type' => 'Airtime To Cash', 'status' => 'Successful',
            ]])]);
        $this->travel(20)->minutes();
        $this->assertSame('provider_pending', $flow->resume($old->id, $user->id)->provider_status);
        $this->assertNotNull($old->fresh()->active_session_key);
        $this->assertDatabaseCount('transactions', 0);
        $this->travel(2)->minutes();
        $this->assertSame('approved', $flow->resume($old->id, $user->id)->status);
        $flow->resume($old->id, $user->id);
        $this->assertDatabaseCount('transactions', 1);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/Airtime-To-Cash'));
        Http::assertSentCount(2);
    }

    public function test_session_expiry_check_cannot_overwrite_a_concurrent_transfer(): void
    {
        $this->enable();
        $user = $this->user();
        $network = $this->network();
        $this->automationSequence(['code' => 4000]);
        $flow = app(AirtimeToCashProviderService::class);
        $old = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $flow->verify($old->id, $user->id, '123456');
        Http::fake(['*/login/with/session/id' => function () use ($flow, $old, $user) {
            $flow->convert($old->id, $user->id, '1234');

            return Http::response(['code' => 4010]);
        }]);
        $this->assertSame('provider_pending', $flow->resume($old->id, $user->id)->provider_status);
        $this->assertNotNull($old->fresh()->active_session_key);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_expired_otp_submit_returns_expired_state_and_manual_records_are_not_discovered(): void
    {
        $this->enable();
        $user = $this->user();
        $network = $this->network();
        $this->automationSequence(['code' => 2000]);
        $flow = app(AirtimeToCashProviderService::class);
        $old = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->travel(20)->minutes();
        $this->assertSame('expired', $flow->verify($old->id, $user->id, '123456')->provider_status);
        $manual = AirtimeToCashRequest::create([
            'user_id' => $user->id, 'network' => 'mtn', 'sender_phone' => '08012345678',
            'destination_number' => '08030000000', 'amount' => 500, 'payout_amount' => 475,
            'transaction_reference' => 'ATC-MANUAL-'.Str::uuid(), 'status' => 'pending',
        ]);
        $this->assertCount(0, $flow->active($user->id));
        $this->assertSame('pending', $manual->fresh()->status);
        $this->expectException(ModelNotFoundException::class);
        $flow->resume($manual->id, $user->id);
    }

    public function test_resume_retries_only_local_confirmed_settlement_once(): void
    {
        $this->enable();
        $user = $this->user();
        $network = $this->network();
        $this->automationSequence(['code' => 2000]);
        $flow = app(AirtimeToCashProviderService::class);
        $old = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        DB::table('airtime_to_cash_requests')->where('id', $old->id)->update([
            'provider_status' => 'settlement_pending', 'provider_confirmed_at' => now(),
        ]);
        $this->assertSame('approved', $flow->resume($old->id, $user->id)->status);
        $flow->resume($old->id, $user->id);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(1475, $user->fresh()->wallet_balance);
        Http::assertSentCount(2); // Only the original quota and OTP calls.
    }

    public function test_documented_automation_flow_requires_pin_and_reaches_transfer_with_original_session_and_reference(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $this->automationSequence(['code' => 2000, 'message' => 'Yello! You have gifted N500.0', 'data' => [
            'amountConverted' => '₦500', 'recipient' => '23481****89', 'automationCharges' => '₦2', 'sessionId' => 'safe-session-id',
        ]]);
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->assertSame('awaiting_otp', $request->provider_status);
        $request = $flow->verify($request->id, $user->id, '123456');
        $this->assertSame('ready_to_transfer', $request->provider_status);
        $view = app(AirtimeToCashProviderController::class)->customerView($request);
        $this->assertFalse($view['transfer_attempted']);
        $this->assertSame('safe-session-id', $request->provider_identifier);
        Http::assertSentCount(3); // quota -> generate OTP -> verify OTP, no transfer yet.
        $this->assertDatabaseCount('transactions', 0);
        try {
            $flow->convert($request->id, $user->id, '');
            $this->fail('Missing PIN accepted');
        } catch (\DomainException) {
            Http::assertSentCount(3);
        }
        $completed = $flow->convert($request->id, $user->id, '1234');
        $this->assertSame('approved', $completed->status);
        $this->assertSame($request->provider_reference, $completed->provider_reference);
        $this->assertGreaterThanOrEqual(10, strlen($request->provider_reference));
        $this->assertLessThanOrEqual(40, strlen($request->provider_reference));
        Http::assertSentCount(4);
        $calls = Http::recorded();
        $this->assertSame([
            'https://automation.airtimetocash.com/api/v1/check/quota/availability',
            'https://automation.airtimetocash.com/api/v1/generate/otp',
            'https://automation.airtimetocash.com/api/v1/verify/otp',
            'https://automation.airtimetocash.com/api/v1/transfer/airtime',
        ], $calls->map(fn ($pair) => $pair[0]->url())->all());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transfer/airtime') && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer test-automation-token') && $r->hasHeader('Accept', 'application/json')
            && $r['reference'] === $request->provider_reference && $r['sessionId'] === 'safe-session-id'
            && $r['pin'] === '1234' && $r['amount'] == 500 && $r['sender'] === '08012345678' && $r['networkName'] === 'MTN');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/verify/otp') && ! $r->hasHeader('Authorization') && $r['otp'] === '123456');
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_unknown_otp_verification_cannot_imply_a_transfer_or_be_reconciled(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $this->automationSequence(['code' => 2000], ['code' => 2000, 'data' => ['airtimeBalance' => '₦500']]); // Missing sessionId.
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $request = $flow->verify($request->id, $user->id, '123456');
        $this->assertSame('setup_review', $request->provider_status);
        $this->assertSame(0, (int) $request->provider_attempt_count);
        $view = app(AirtimeToCashProviderController::class)->customerView($request);
        $this->assertStringContainsString('No transfer has been submitted', $view['message']);
        $this->assertSame('setup_review', $flow->convert($request->id, $user->id, '1234')->provider_status);
        // Even a late/incorrect success result cannot settle a never-submitted transfer.
        $flow->recordResult($request->id, new ProviderResult('success', convertedAmount: 500));
        Http::assertSentCount(3);
        $this->assertDatabaseCount('transactions', 0);
        $this->expectException(\DomainException::class);
        app(AirtimeToCashReconciliationService::class)->reconcile($request->id);
    }

    public function test_legacy_pre_transfer_manual_review_is_serialized_as_setup_and_reconciliation_is_rejected(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $this->automationSequence(['code' => 2000]);
        $request = app(AirtimeToCashProviderService::class)->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        DB::table('airtime_to_cash_requests')->where('id', $request->id)->update(['provider_status' => 'manual_review']);
        $view = app(AirtimeToCashProviderController::class)->customerView($request->fresh());
        $this->assertSame('setup_review', $view['state']);
        $this->assertFalse($view['transfer_attempted']);
        $this->expectExceptionMessage('No transfer attempt is recorded');
        app(AirtimeToCashReconciliationService::class)->reconcile($request->id);
    }

    public function test_automation_reconcile_reports_unsupported_lookup_without_faking_a_state_change(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $this->automationSequence(['code' => 4000]);
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $flow->verify($request->id, $user->id, '123456');
        $flow->convert($request->id, $user->id, '1234');
        try {
            app(AirtimeToCashReconciliationService::class)->reconcile($request->id);
            $this->fail('Unsupported lookup accepted');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('no documented transaction lookup', $e->getMessage());
        }
        $this->assertSame('provider_pending', $request->fresh()->provider_status);
        Http::assertSentCount(4);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_transport_preflight_failure_is_not_an_unknown_delivered_transfer_and_logs_are_safe(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $logs = [];
        Log::shouldReceive('info')->andReturnUsing(function ($event, $context) use (&$logs) {
            $logs[] = $context;
        });
        $this->automationSequence(['code' => 2000], ['code' => 2000, 'message' => 'echoed 123456 1234 safe-session-id test-automation-token', 'data' => ['sessionId' => 'safe-session-id', 'airtimeBalance' => '₦1,000']]);
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $flow->verify($request->id, $user->id, '123456');
        config(['airtime_to_cash.providers.airtime_to_cash_automation.token' => '']);
        $failed = $flow->convert($request->id, $user->id, '1234');
        $this->assertSame('pending', $failed->status);
        $this->assertSame('setup_review', $failed->provider_status);
        $this->assertFalse($failed->lifecycle['transfer_submitted']);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('transactions', 0);
        $allLogs = $logs;
        $logs = array_values(array_filter($logs, fn ($log) => $log['operation'] !== 'lifecycle_transition'));
        $this->assertCount(4, $logs);
        $this->assertSame(['quota', 'otp', 'verify', 'convert'], array_column($logs, 'operation'));
        $this->assertSame([$request->transaction_reference], array_values(array_unique(array_column($logs, 'internal_reference'))));
        $this->assertFalse($logs[3]['dispatch_attempted']);
        $this->assertSame('transport_preflight_rejected', $logs[3]['sanitized_message']);
        foreach (['123456', '1234', 'safe-session-id', 'test-automation-token', '08012345678'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($allLogs));
        }
    }
    public function test_customer_availability_is_identical_for_customer_owner_admin_and_support_without_permissions(): void
    {
        Http::fake();
        $this->enable();
        $network = $this->network();
        foreach (['customer', 'owner', 'admin', 'support'] as $name) {
            $role = \App\Models\Role::create(['name' => $name, 'slug' => $name, 'is_active' => true, 'is_staff' => $name !== 'customer']);
            $user = $this->user();
            $user->update(['role_id' => $role->id, 'wallet_balance' => 0, 'is_verified' => false]);
            $response = $this->actingAs($user)->getJson('/api/customer/airtime-to-cash/provider/options');
            $response->assertOk();
            $this->assertSame(['provider_available' => true, 'automated_available' => true, 'manual_available' => true], $response->json('data'));
            $this->actingAs($user)->getJson('/api/admin/airtime-to-cash/providers')->assertForbidden();
        }
        Http::assertNothingSent();
        // Disabling the manual method must not withdraw automated conversion.
        $network->update(['airtime_to_cash_active' => false]);
        $this->getJson('/api/customer/airtime-to-cash/provider/options')
            ->assertOk()->assertJsonPath('data.automated_available', true)->assertJsonPath('data.manual_available', false);
    }

    public function test_customer_availability_checks_runtime_network_and_configuration_without_secrets(): void
    {
        Http::fake();
        $this->enable();
        $network = $this->network();
        $this->actingAs($this->user());
        config(['airtime_to_cash.provider_mode_enabled' => false]);
        $this->getJson('/api/customer/airtime-to-cash/provider/options')->assertOk()
            ->assertJsonPath('data.automated_available', false)->assertJsonPath('data.manual_available', true);
        config(['airtime_to_cash.provider_mode_enabled' => true, 'airtime_to_cash.providers.airtime_to_cash_automation.base_url' => 'https://invalid.example']);
        $this->getJson('/api/customer/airtime-to-cash/provider/options')->assertOk()->assertJsonPath('data.automated_available', false);
        config(['airtime_to_cash.providers.airtime_to_cash_automation.base_url' => 'https://automation.airtimetocash.com']);
        $this->getJson('/api/customer/airtime-to-cash/provider/options?network_id='.$network->id)->assertOk()->assertJsonPath('data.automated_available', true);
        $this->getJson('/api/customer/airtime-to-cash/provider/options?network_id=999999')->assertOk()->assertJsonPath('data.automated_available', false);
        Http::assertNothingSent();
    }

    public function test_impersonated_customer_can_discover_methods_but_cannot_start_a_transfer(): void
    {
        Http::fake();
        $this->enable();
        $this->network();
        $user = $this->user();
        $token = $user->createToken('impersonated-test');
        $session = \App\Models\AuthSession::create([
            'user_id' => $user->id, 'channel' => 'impersonation', 'access_token_id' => $token->accessToken->id,
            'last_active_at' => now(), 'idle_expires_at' => now()->addMinutes(10),
            'absolute_expires_at' => now()->addHour(),
        ]);
        $this->withToken($token->plainTextToken)
            ->getJson('/api/customer/airtime-to-cash/provider/options')->assertOk()
            ->assertJsonPath('data.automated_available', true)->assertJsonPath('data.manual_available', true);
        $this->getJson('https://localhost/api/customer/airtime-to-cash/provider/active')->assertOk();
        $this->postJson('https://localhost/api/customer/airtime-to-cash/provider/start', [])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_live_call_flag_applies_to_customer_availability_even_with_configured_provider(): void
    {
        $this->enable();
        $this->network();
        config(['airtime_to_cash.live_calls_enabled' => false]);
        $this->actingAs($this->user())->getJson('/api/customer/airtime-to-cash/provider/options')
            ->assertOk()->assertJsonPath('data.automated_available', false)->assertJsonPath('data.manual_available', true);
    }

    public function test_discovery_uses_canonical_state_even_if_a_reservation_key_is_missing(): void
    {
        $this->enable();
        $network = $this->network();
        $user = $this->user();
        $this->automationSequence(['code' => 4000]);
        $flow = app(AirtimeToCashProviderService::class);
        $request = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $request->update(['active_session_key' => null]);
        $this->assertSame([$request->id], $flow->active($user->id)->pluck('id')->all());
        $replayed = $flow->start($user->id, $network->id, 500, 475, '08012345678', (string) Str::uuid());
        $this->assertSame($request->id, $replayed->id);
        $this->assertDatabaseCount('airtime_to_cash_requests', 1);
        $this->assertSame('Awaiting verification', $request->lifecycle['label']);
        $this->assertFalse($request->lifecycle['can_approve']);
        $this->assertFalse($request->lifecycle['can_reconcile']);
        Http::assertSentCount(2);
    }

}
