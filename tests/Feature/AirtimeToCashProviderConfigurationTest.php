<?php

namespace Tests\Feature;

use App\Models\AirtimeToCashProviderSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\AirtimeToCash\AirtimeToCashProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_connection_failure_is_sanitised_and_twofast_uses_lookup_only(): void
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
            'https://2fast.com.ng/api/transaction-history' => Http::sequence()
                ->push([
                    'status' => 'error',
                    'message' => 'Transaction not found. Raw diagnostics are deliberately ignored.',
                    'debug' => 'upstream-private-data',
                ], 200)
                ->push(['message' => 'private'], 401),
        ]);

        $response = $this->actingAs($admin)->postJson(
            '/api/admin/airtime-to-cash/providers/2fast/test',
        )->assertOk()->assertJsonPath('data.connected', true);
        $this->assertStringNotContainsString('upstream-private-data', $response->getContent());
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/transaction-history')
            && str_starts_with((string) $request['reference'], 'ATC-HEALTH-'));

        $response = $this->actingAs($admin)->postJson('/api/admin/airtime-to-cash/providers/2fast/test')
            ->assertOk()->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.message', 'Authentication rejected by provider. Check the configured API key.');
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertSame('failed', AirtimeToCashProviderSetting::findOrFail('2fast')->health_status);
    }
}
