<?php

namespace Tests\Feature;

use App\Models\AirtimeToCashProviderSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\AirtimeToCash\AirtimeToCashProviderConfiguration;
use App\Services\AirtimeToCash\AirtimeToCashProviderManager;
use App\Services\AirtimeToCash\ProviderTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class AirtimeToCashProviderConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'airtime_to_cash.provider_mode_enabled' => false,
            'airtime_to_cash.live_calls_enabled' => false,
            'airtime_to_cash.providers.airtime_to_cash_automation.token' => null,
            'airtime_to_cash.providers.2fast.token' => null,
        ]);
    }

    private function user(): User
    {
        $id = Str::lower(Str::random(10));

        return User::create([
            'username' => $id,
            'fullname' => 'Provider Configuration Test',
            'email' => "{$id}@example.test",
            'phone' => '080'.random_int(10000000, 99999999),
            'password' => 'password',
            'status' => 'active',
            'is_active' => true,
            'is_verified' => true,
        ]);
    }

    private function admin(): User
    {
        $role = Role::create([
            'name' => 'Conversion provider administrator',
            'slug' => 'conversion-provider-administrator',
            'is_staff' => true,
            'is_active' => true,
        ]);
        $permission = Permission::firstOrCreate(
            ['slug' => 'airtime_to_cash'],
            ['name' => 'Airtime to Cash'],
        );
        $role->permissions()->attach($permission);
        $user = $this->user();
        $user->update(['role_id' => $role->id]);

        return $user;
    }

    private function providerPayload(array $overrides = []): array
    {
        return array_replace([
            'enabled' => true,
            'priority' => 1,
            'base_url' => 'https://automation.airtimetocash.com',
        ], $overrides);
    }

    public function test_only_authorised_staff_can_read_or_manage_provider_configuration(): void
    {
        $customer = $this->user();

        $this->actingAs($customer)->getJson('/api/admin/airtime-to-cash/providers')->assertForbidden();
        $this->actingAs($customer)->putJson(
            '/api/admin/airtime-to-cash/providers/airtime_to_cash_automation',
            $this->providerPayload(['token' => 'must-not-be-stored']),
        )->assertForbidden();
        $this->actingAs($customer)->postJson(
            '/api/admin/airtime-to-cash/providers/airtime_to_cash_automation/test',
        )->assertForbidden();

        $this->actingAs($this->admin())->getJson('/api/admin/airtime-to-cash/providers')
            ->assertOk()
            ->assertJsonPath('data.providers.0.credentials.0.secret', true)
            ->assertJsonPath('data.providers.0.credentials.0.configured', false)
            ->assertJsonMissing(['token' => 'must-not-be-stored']);
    }

    public function test_admin_can_store_replace_and_preserve_an_encrypted_credential_without_serialising_it(): void
    {
        $admin = $this->admin();
        $secret = 'first-provider-secret';
        $url = '/api/admin/airtime-to-cash/providers/airtime_to_cash_automation';

        $response = $this->actingAs($admin)->putJson($url, $this->providerPayload([
            'priority' => 4,
            'token' => $secret,
        ]))->assertOk()
            ->assertJsonMissing(['token' => $secret]);
        $provider = collect($response->json('data.providers'))->firstWhere('key', 'airtime_to_cash_automation');
        $this->assertTrue($provider['configured']);
        $this->assertArrayNotHasKey('token', $provider);
        $this->assertArrayNotHasKey('api_key', $provider);

        $raw = DB::table('airtime_to_cash_provider_settings')
            ->where('provider', 'airtime_to_cash_automation')->value('token');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, Crypt::decryptString($raw));
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($secret, AirtimeToCashProviderSetting::firstOrFail()->toJson());

        $this->actingAs($admin)->putJson($url, $this->providerPayload([
            'enabled' => false,
            'priority' => 7,
            'token' => '',
        ]))->assertOk();
        $this->assertSame($raw, DB::table('airtime_to_cash_provider_settings')
            ->where('provider', 'airtime_to_cash_automation')->value('token'));
        $this->assertFalse(AirtimeToCashProviderSetting::findOrFail('airtime_to_cash_automation')->enabled);
        $this->assertSame(7, AirtimeToCashProviderSetting::findOrFail('airtime_to_cash_automation')->priority);

        $replacement = 'replacement-provider-secret';
        $this->actingAs($admin)->putJson($url, $this->providerPayload(['token' => $replacement]))
            ->assertOk()->assertJsonMissing(['token' => $replacement]);
        $this->assertSame($replacement, AirtimeToCashProviderSetting::findOrFail('airtime_to_cash_automation')->token);

        $audit = DB::table('audit_logs')->get()->toJson();
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => AirtimeToCashProviderSetting::class,
            'auditable_id' => 'airtime_to_cash_automation',
        ]);
        $this->assertStringNotContainsString($secret, $audit);
        $this->assertStringNotContainsString($replacement, $audit);
    }

    public function test_admin_controls_session_login_before_transfer_without_touching_other_configuration(): void
    {
        $admin = $this->admin();
        $url = '/api/admin/airtime-to-cash/providers/airtime_to_cash_automation';
        $row = fn () => (array) DB::table('airtime_to_cash_provider_settings')->where('provider', 'airtime_to_cash_automation')->first();
        $automation = fn () => collect($this->actingAs($admin)->getJson('/api/admin/airtime-to-cash/providers')->assertOk()
            ->json('data.providers'))->firstWhere('key', 'airtime_to_cash_automation');
        $this->actingAs($admin)->putJson($url, $this->providerPayload(['priority' => 3, 'token' => 'kept-provider-secret']))->assertOk();
        $before = $row();

        // Off by default, preserving current production behaviour, and reported as the default.
        $this->assertSame([false, 'default'], [$automation()['session_login_before_transfer'], $automation()['session_login_before_transfer_source']]);

        $this->actingAs($admin)->putJson($url, $this->providerPayload(['priority' => 3, 'session_login_before_transfer' => true]))->assertOk();
        $this->assertSame([true, 'admin'], [$automation()['session_login_before_transfer'], $automation()['session_login_before_transfer_source']]);
        // Credential, enablement, priority, URL and health are untouched.
        $after = $row();
        foreach (['token', 'enabled', 'priority', 'base_url', 'health_status', 'health_message'] as $column) {
            $this->assertSame($before[$column], $after[$column], $column);
        }
        $this->assertSame('kept-provider-secret', AirtimeToCashProviderSetting::findOrFail('airtime_to_cash_automation')->token);

        // A client that does not send the field keeps the stored choice.
        $this->actingAs($admin)->putJson($url, $this->providerPayload(['priority' => 3]))->assertOk();
        $this->assertTrue(AirtimeToCashProviderSetting::findOrFail('airtime_to_cash_automation')->session_login_before_transfer);

        // Admin configuration wins over the environment fallback.
        config([
            'airtime_to_cash.providers.airtime_to_cash_automation.session_login_before_transfer' => true,
            'airtime_to_cash.providers.airtime_to_cash_automation.session_login_before_transfer_source' => 'env',
        ]);
        $this->actingAs($admin)->putJson($url, $this->providerPayload(['priority' => 3, 'session_login_before_transfer' => false]))->assertOk();
        $this->assertSame([false, 'admin'], [$automation()['session_login_before_transfer'], $automation()['session_login_before_transfer_source']]);

        AirtimeToCashProviderSetting::where('provider', 'airtime_to_cash_automation')->update(['session_login_before_transfer' => null]);
        $this->assertSame([true, 'env'], [$automation()['session_login_before_transfer'], $automation()['session_login_before_transfer_source']]);
        $this->assertStringContainsString('session_login_before_transfer', DB::table('audit_logs')->get()->toJson());

        // 2FAST has no session login to configure.
        $this->actingAs($admin)->putJson('/api/admin/airtime-to-cash/providers/2fast', ['enabled' => false, 'priority' => 2,
            'base_url' => 'https://2fast.com.ng', 'session_login_before_transfer' => true])->assertUnprocessable();
        $this->assertNull(AirtimeToCashProviderSetting::find('2fast')?->session_login_before_transfer);

        // Never exposed to customers.
        $customer = $this->user();
        $this->actingAs($customer)->getJson('/api/admin/airtime-to-cash/providers')->assertForbidden();
        foreach (['/api/customer/airtime-to-cash/provider/options', '/api/customer/airtime-to-cash/networks'] as $endpoint) {
            $this->assertStringNotContainsString('session_login', $this->actingAs($customer)->getJson($endpoint)->assertOk()->getContent(), $endpoint);
        }
    }

    public function test_provider_allowlist_url_and_required_credential_validation_are_enforced(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(
            '/api/admin/airtime-to-cash/providers/not-a-provider',
            $this->providerPayload(),
        )->assertUnprocessable();
        $this->actingAs($admin)->putJson(
            '/api/admin/airtime-to-cash/providers/airtime_to_cash_automation',
            $this->providerPayload(['base_url' => 'https://example.com']),
        )->assertUnprocessable()->assertJsonPath('message', 'Use the provider’s official HTTPS base URL.');
        $this->actingAs($admin)->putJson(
            '/api/admin/airtime-to-cash/providers/airtime_to_cash_automation',
            $this->providerPayload(),
        )->assertUnprocessable()->assertJsonPath('message', 'Add a provider credential before enabling this provider.');
    }

    public function test_database_runtime_controls_override_environment_and_validate_live_calls(): void
    {
        config([
            'airtime_to_cash.provider_mode_enabled' => true,
            'airtime_to_cash.live_calls_enabled' => false,
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/admin/airtime-to-cash/providers')
            ->assertOk()->assertJsonPath('data.mode_enabled', true)->assertJsonPath('data.live_calls_enabled', false);
        $this->actingAs($admin)->putJson('/api/admin/airtime-to-cash/providers', [
            'mode_enabled' => false,
            'live_calls_enabled' => true,
        ])->assertUnprocessable();

        $this->actingAs($admin)->putJson(
            '/api/admin/airtime-to-cash/providers/airtime_to_cash_automation',
            $this->providerPayload(['token' => 'runtime-secret']),
        )->assertOk();
        $this->actingAs($admin)->putJson('/api/admin/airtime-to-cash/providers', [
            'mode_enabled' => true,
            'live_calls_enabled' => true,
        ])->assertOk()->assertJsonPath('data.live_calls_enabled', true);

        $setting = Setting::firstOrFail();
        $this->assertTrue($setting->airtime_to_cash_provider_mode_enabled);
        $this->assertTrue($setting->airtime_to_cash_live_calls_enabled);
        $this->assertTrue(app(AirtimeToCashProviderManager::class)->modeAvailable());
    }

    public function test_connection_check_updates_sanitised_health_without_triggering_a_transfer(): void
    {
        $admin = $this->admin();
        $secret = 'health-check-secret';
        $this->actingAs($admin)->putJson(
            '/api/admin/airtime-to-cash/providers/airtime_to_cash_automation',
            $this->providerPayload(['token' => $secret]),
        )->assertOk();
        Http::fake([
            'https://automation.airtimetocash.com/api/v1/check/quota/availability' => Http::response(['code' => 5030], 200),
        ]);

        $response = $this->actingAs($admin)->postJson(
            '/api/admin/airtime-to-cash/providers/airtime_to_cash_automation/test',
        )->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.message', 'Authentication successful.')
            ->assertJsonMissing(['token' => $secret]);
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertSame('connected', AirtimeToCashProviderSetting::findOrFail('airtime_to_cash_automation')->health_status);
        $this->assertNotNull(AirtimeToCashProviderSetting::findOrFail('airtime_to_cash_automation')->last_health_check_at);
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/check/quota/availability')
            && ! str_contains($request->url(), 'transfer'));
    }

    public function test_connection_failure_is_sanitised_and_twofast_uses_non_transactional_endpoint(): void
    {
        $admin = $this->admin();
        $url = '/api/admin/airtime-to-cash/providers/2fast';
        $this->actingAs($admin)->putJson($url, [
            'enabled' => true,
            'priority' => 2,
            'base_url' => 'https://2fast.com.ng',
            'token' => 'twofast-health-secret',
        ])->assertOk();
        Http::fake([
            'https://2fast.com.ng/api/data-plans' => Http::sequence()
                ->push([
                    'status' => 'success',
                    'network' => 'MTN',
                    'count' => 1,
                    'data' => [['plan_id' => '001', 'volume' => '1GB']],
                    'debug' => 'upstream-private-data',
                ], 200)
                ->push(['message' => 'private'], 401),
        ]);

        $response = $this->actingAs($admin)->postJson(
            '/api/admin/airtime-to-cash/providers/2fast/test',
        )->assertOk()->assertJsonPath('data.connected', true);
        $this->assertStringNotContainsString('upstream-private-data', $response->getContent());
        Http::assertSentCount(1);
        // The credential check must never touch an endpoint that can start a conversion.
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'Airtime-To-Cash'));
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://2fast.com.ng/api/data-plans'
            && $request->method() === 'POST'
            && $request['network'] === 1
            && $request->hasHeader('Authorization', 'Bearer twofast-health-secret')
            && $request->hasHeader('Content-Type', 'application/json'));

        $response = $this->actingAs($admin)->postJson('/api/admin/airtime-to-cash/providers/2fast/test')
            ->assertOk()->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.message', 'Authentication rejected by 2FAST. Check the configured API key.');
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertSame('failed', AirtimeToCashProviderSetting::findOrFail('2fast')->health_status);
    }

    /**
     * Only 401 may be reported to an admin as a credential problem. Every other
     * documented 2FAST failure mode must name its own cause.
     */
    public function test_twofast_health_classifies_each_documented_failure_mode(): void
    {
        AirtimeToCashProviderSetting::create([
            'provider' => '2fast', 'enabled' => true, 'priority' => 2,
            'base_url' => 'https://2fast.com.ng', 'token' => 'twofast-key',
        ]);
        $manager = app(AirtimeToCashProviderManager::class);
        $cases = [
            [401, ['message' => 'Unauthenticated.'], false, 'Authentication rejected by 2FAST. Check the configured API key.'],
            [403, ['message' => 'IP not whitelisted.'], false, 'Access denied by 2FAST. Check KYC status and API IP whitelist configuration.'],
            [404, [], false, '2FAST did not recognise the connection-test endpoint. Check the configured base URL.'],
            [429, [], false, 'Provider rate limit reached. Try the connection test later.'],
            [503, [], false, '2FAST is temporarily unavailable. This does not indicate a credential problem.'],
            [500, [], false, '2FAST is temporarily unavailable. This does not indicate a credential problem.'],
            [400, ['status' => 'error', 'message' => 'Validation failed.'], false,
                '2FAST authenticated the request but rejected it (HTTP 400). This is not a credential problem.'],
            [422, ['status' => 'error'], false,
                '2FAST authenticated the request but rejected it (HTTP 422). This is not a credential problem.'],
            [200, ['status' => 'error', 'message' => 'Something else.'], false,
                '2FAST authenticated the request but returned an unrecognised response. This is not a credential problem.'],
            [200, ['status' => 'success', 'network' => 'MTN', 'count' => 0, 'data' => []], true, 'Authentication successful.'],
        ];
        // One sequence: Http::fake() appends stubs, so re-faking per case would keep the first.
        $sequence = Http::sequence();
        foreach ($cases as [$status, $body]) {
            $sequence->push($body, $status);
        }
        Http::fake(['https://2fast.com.ng/api/data-plans' => $sequence]);
        foreach ($cases as [$status, $body, $connected, $message]) {
            $result = $manager->testConnection('2fast');
            $this->assertSame($connected, $result->connected, "HTTP {$status} connected flag");
            $this->assertSame($message, $result->message, "HTTP {$status} message");
        }
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'Airtime-To-Cash'));
    }

    public function test_twofast_health_reports_a_transport_failure_as_unreachable_not_rejected(): void
    {
        AirtimeToCashProviderSetting::create([
            'provider' => '2fast', 'enabled' => true, 'priority' => 2,
            'base_url' => 'https://2fast.com.ng', 'token' => 'twofast-key',
        ]);
        Http::fake(['https://2fast.com.ng/api/data-plans' => fn () => throw new ConnectionException('timed out')]);

        $result = app(AirtimeToCashProviderManager::class)->testConnection('2fast');
        $this->assertFalse($result->connected);
        $this->assertSame(
            'Could not reach 2FAST. The request failed before any response (network, DNS or timeout).',
            $result->message,
        );
    }

    public function test_twofast_credential_is_trimmed_before_authorization_header_is_built(): void
    {
        // An env-sourced key keeps whatever whitespace the paste carried.
        config(['airtime_to_cash.providers.2fast.token' => "  twofast-padded-key\n"]);
        Http::fake(['https://2fast.com.ng/api/data-plans' => Http::response(['status' => 'success', 'data' => []])]);

        $this->assertTrue(app(AirtimeToCashProviderManager::class)->testConnection('2fast')->connected);
        Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer twofast-padded-key'));
    }

    /**
     * Admin paste → save → encrypted column → resolve → transport → wire header.
     * Proves Vendify sends exactly the pasted key: not stale, ciphertext, masked
     * placeholder, environment fallback, or whitespace-corrupted.
     */
    public function test_twofast_credential_survives_the_full_admin_to_wire_lifecycle_unchanged(): void
    {
        $admin = $this->admin();
        $key = 'tf_live_AbCdEf0123456789ZyXwVu';
        // A stored credential must always beat this environment fallback.
        config(['airtime_to_cash.providers.2fast.token' => 'environment-fallback-key']);
        $url = '/api/admin/airtime-to-cash/providers/2fast';

        // 1. The pasted value arrives with stray whitespace; the backend must cope alone.
        $this->actingAs($admin)->putJson($url, [
            'enabled' => true, 'priority' => 2, 'base_url' => 'https://2fast.com.ng',
            'token' => "  {$key}\r\n",
        ])->assertOk();

        // 2. Encrypted exactly once, trimmed, never stored or echoed in the clear.
        $raw = DB::table('airtime_to_cash_provider_settings')->where('provider', '2fast')->value('token');
        $this->assertNotSame($key, $raw);
        $this->assertStringNotContainsString($key, (string) $raw);
        $this->assertSame($key, Crypt::decryptString($raw));
        $this->assertSame($key, AirtimeToCashProviderSetting::findOrFail('2fast')->token);

        // 3. Database wins over environment, and resolves to the trimmed plaintext.
        $resolved = app(AirtimeToCashProviderConfiguration::class)->resolved('2fast');
        $this->assertSame('database', $resolved['token_source']);
        $this->assertSame($key, $resolved['token']);

        // 4. Exactly one Bearer, exactly the pasted key, on the documented request.
        Http::fake(['https://2fast.com.ng/api/data-plans' => Http::response(['status' => 'success', 'data' => []])]);
        $this->actingAs($admin)->postJson($url.'/test')->assertOk()->assertJsonPath('data.connected', true);
        Http::assertSent(function (ClientRequest $request) use ($key) {
            $this->assertSame('https://2fast.com.ng/api/data-plans', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame(['network' => 1, 'limit' => 1], $request->data());
            $this->assertSame(['application/json'], $request->header('Accept'));
            $this->assertSame(['application/json'], $request->header('Content-Type'));
            $this->assertSame(["Bearer {$key}"], $request->header('Authorization'));
            $this->assertDoesNotMatchRegularExpression('/Bearer\s+Bearer/i', $request->header('Authorization')[0]);

            return true;
        });

        // 5. The fingerprint of what went on the wire equals the fingerprint of the pasted key.
        $this->assertSame(ProviderTransport::fingerprint($key), ProviderTransport::fingerprint($resolved['token']));
        $this->assertSame(12, strlen(ProviderTransport::fingerprint($key)));
        $this->assertStringNotContainsString($key, ProviderTransport::fingerprint($key));
    }

    public function test_credential_fingerprint_command_compares_without_revealing_the_credential(): void
    {
        $key = 'tf_live_fingerprint_case';
        AirtimeToCashProviderSetting::create([
            'provider' => '2fast', 'enabled' => true, 'priority' => 2,
            'base_url' => 'https://2fast.com.ng', 'token' => $key,
        ]);

        $this->artisan('airtime-to-cash:credential-fingerprint 2fast')
            ->expectsOutputToContain(ProviderTransport::fingerprint($key))
            ->doesntExpectOutputToContain($key)
            ->assertExitCode(0);

        $this->artisan('airtime-to-cash:credential-fingerprint 2fast --compare')
            ->expectsQuestion('Paste the API key from the 2FAST dashboard (input is hidden)', $key)
            ->expectsOutputToContain('MATCH')
            ->doesntExpectOutputToContain($key)
            ->assertExitCode(0);

        $this->artisan('airtime-to-cash:credential-fingerprint 2fast --compare')
            ->expectsQuestion('Paste the API key from the 2FAST dashboard (input is hidden)', 'a-different-key')
            ->expectsOutputToContain('DIFFERENT')
            ->assertExitCode(0);
    }

    public function test_twofast_health_logs_safe_diagnostics_and_never_the_credential(): void
    {
        $key = 'tf_live_diagnostic_case_key';
        AirtimeToCashProviderSetting::create([
            'provider' => '2fast', 'enabled' => true, 'priority' => 2,
            'base_url' => 'https://2fast.com.ng', 'token' => $key,
        ]);
        Http::fake(['https://2fast.com.ng/api/data-plans' => Http::response(['message' => 'Unauthenticated.'], 401)]);
        Log::spy();

        $this->assertFalse(app(AirtimeToCashProviderManager::class)->testConnection('2fast')->connected);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($key) {
            if ($message !== 'airtime_to_cash.provider_call' || ($context['operation'] ?? null) !== 'health') {
                return false;
            }
            $this->assertSame('2fast', $context['provider']);
            $this->assertSame(401, $context['http_status']);
            $this->assertSame('auth_rejected', $context['provider_error_class']);
            $this->assertSame('2fast.com.ng', $context['request_host']);
            $this->assertSame('/api/data-plans', $context['request_path']);
            $this->assertSame('bearer', $context['auth_scheme']);
            $this->assertTrue($context['auth_credential_present']);
            $this->assertSame('database', $context['credential_source']);
            $this->assertSame(strlen($key), $context['auth_credential_length']);
            $this->assertSame(ProviderTransport::fingerprint($key), $context['auth_credential_fingerprint']);
            // The credential itself must appear nowhere in the log line.
            $this->assertStringNotContainsString($key, json_encode($context));

            return true;
        })->once();
    }

    public function test_twofast_connection_test_requires_a_credential_and_sends_nothing_without_one(): void
    {
        Http::fake();
        $this->expectException(\DomainException::class);
        try {
            app(AirtimeToCashProviderManager::class)->testConnection('2fast');
        } finally {
            Http::assertNothingSent();
        }
    }
}
