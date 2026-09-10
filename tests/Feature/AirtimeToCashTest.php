<?php

namespace Tests\Feature;

use App\Models\AirtimeToCashRequest;
use App\Models\Discount;
use App\Models\Network;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AirtimeToCashSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AirtimeToCashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function mtn(bool $enabled = true): Network
    {
        return Network::create([
            'name' => 'MTN',
            'active' => true,
            'airtime_to_cash_active' => $enabled,
            'airtime_to_cash_destination_number' => '08030000000',
            'airtime_to_cash_min' => 100,
            'airtime_to_cash_max' => 50000,
        ]);
    }

    private function rate(): Discount
    {
        return Discount::create([
            'name' => 'MTN Airtime to Cash',
            'service_type' => 'airtimeToCash',
            // Rates historically use a lowercase identifier while Network
            // names are display-cased in the customer catalogue.
            'network' => 'mtn',
            'discount_type' => 'percentage',
            'value' => 5,
            'active' => true,
        ]);
    }

    private function user(): User
    {
        $id = Str::lower(Str::random(10));

        return User::create([
            'username' => $id,
            'fullname' => 'Airtime Test User',
            'email' => "{$id}@example.test",
            'phone' => '080'.random_int(10000000, 99999999),
            'password' => 'password',
            'status' => 'active',
            'is_active' => true,
            'is_verified' => true,
        ]);
    }

    public function test_enabled_mtn_catalog_quote_and_submission_use_the_same_network(): void
    {
        $network = $this->mtn();
        $this->rate();
        $user = $this->user();

        $catalog = $this->actingAs($user)->getJson('/api/customer/catalog/networks')->assertOk();
        $mtn = collect($catalog->json('data'))->firstWhere('id', $network->id);

        $this->assertSame('MTN', $mtn['name']);
        $this->assertTrue($mtn['airtime_to_cash_active']);
        $this->assertSame('08030000000', $mtn['airtime_to_cash_destination_number']);
        $this->assertEquals(100, $mtn['airtime_to_cash_min']);
        $this->assertEquals(50000, $mtn['airtime_to_cash_max']);

        $this->actingAs($user)
            ->getJson('/api/vtu/airtimeToCash/discount?network=MTN&amount=100')
            ->assertOk()
            ->assertJsonPath('data.final_amount', 95);

        $this->actingAs($user)->postJson('/api/customer/airtime-to-cash', [
            'network_id' => $network->id,
            'network' => 'MTN',
            'amount' => 100,
            'sender_phone' => '08012345678',
        ])->assertCreated()
            ->assertJsonPath('data.network', 'MTN')
            ->assertJsonPath('data.payout_amount', '95.00');

        $this->assertDatabaseHas(AirtimeToCashRequest::class, [
            'network' => 'MTN',
            'amount' => 100,
            'payout_amount' => 95,
            'status' => 'pending',
        ]);
    }

    public function test_disabled_mtn_is_rejected_even_when_a_rate_exists(): void
    {
        $network = $this->mtn(false);
        $this->rate();

        $this->actingAs($this->user())->postJson('/api/customer/airtime-to-cash', [
            'network_id' => $network->id,
            'network' => 'MTN',
            'amount' => 100,
            'sender_phone' => '08012345678',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('network');
    }

    public function test_legacy_network_name_is_case_insensitive_and_persists_the_canonical_name(): void
    {
        $this->mtn();
        $this->rate();

        $this->actingAs($this->user())->postJson('/api/customer/airtime-to-cash', [
            'network' => 'mtn',
            'amount' => 100,
            'sender_phone' => '08012345678',
        ])->assertCreated()
            ->assertJsonPath('data.network', 'MTN')
            ->assertJsonPath('data.payout_amount', '95.00');
    }

    public function test_missing_rate_is_not_presented_as_a_zero_percent_deduction(): void
    {
        $this->mtn();

        $this->actingAs($this->user())
            ->getJson('/api/vtu/airtimeToCash/discount?network=MTN&amount=100')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Conversion rate is currently unavailable. Configure one active Airtime-to-Cash rate.');
    }

    public function test_network_update_invalidates_the_cached_customer_catalog(): void
    {
        $network = $this->mtn(false);
        $user = $this->user();

        $first = $this->actingAs($user)->getJson('/api/customer/catalog/networks')->assertOk();
        $this->assertFalse(collect($first->json('data'))->firstWhere('id', $network->id)['airtime_to_cash_active']);

        $network->update(['airtime_to_cash_active' => true]);

        $second = $this->actingAs($user)->getJson('/api/customer/catalog/networks')->assertOk();
        $this->assertTrue(collect($second->json('data'))->firstWhere('id', $network->id)['airtime_to_cash_active']);
        $second->assertHeader('X-Cache', 'MISS');
    }

    private function admin(): User
    {
        $role = Role::create(['name' => 'Conversion reviewer', 'slug' => 'conversion-reviewer', 'is_staff' => true, 'is_active' => true]);
        $permission = Permission::firstOrCreate(['slug' => 'airtime_to_cash'], ['name' => 'Airtime to Cash']);
        $role->permissions()->attach($permission);
        $user = $this->user();
        $user->update(['role_id' => $role->id]);

        return $user;
    }

    private function config(array $overrides = []): array
    {
        return array_replace(['enabled' => true, 'destination_number' => '08030000000',
            'min_amount' => 100, 'max_amount' => 50000, 'rate_type' => 'percentage',
            'rate_value' => 5, 'rate_active' => true, 'starts_at' => null, 'ends_at' => null], $overrides);
    }

    public static function networkNames(): array
    {
        return [['MTN', 'mtn'], ['mtn', 'MTN'], ['Airtel', 'airtel'], ['GLO', 'glo'],
            ['T2', '9mobile'], ['9mobile', 't2'], ['Etisalat', '9mobile'], ['T2 / 9mobile', '9mobile']];
    }

    #[DataProvider('networkNames')]
    public function test_all_network_aliases_quote_and_submit_by_id_and_legacy_name(string $name, string $rateName): void
    {
        $network = $this->mtn();
        $network->update(['name' => $name]);
        $this->rate()->update(['network' => $rateName]);
        $this->assertEquals(475, Discount::getDiscountedAmount(500, 'airtimeToCash', $name));
        $user = $this->user();
        foreach (['network_id='.$network->id, 'network='.urlencode($rateName)] as $identifier) {
            $this->actingAs($user)->getJson('/api/vtu/airtimeToCash/discount?'.$identifier.'&amount=500')
                ->assertOk()->assertJsonPath('data.network_id', $network->id)->assertJsonPath('data.payout_amount', 475);
        }
        $this->postJson('/api/customer/airtime-to-cash', ['network_id' => $network->id, 'amount' => 500,
            'quoted_payout' => 475, 'sender_phone' => '08012345678'])->assertCreated()->assertJsonPath('data.payout_amount', '475.00');
    }

    public static function unavailableCases(): array
    {
        return [
            'disabled' => [['airtime_to_cash_active' => false], [], 'disabled'],
            'destination' => [['airtime_to_cash_destination_number' => null], [], 'destination'],
            'zero min' => [['airtime_to_cash_min' => 0], [], 'limits'],
            'reversed limits' => [['airtime_to_cash_max' => 50], [], 'limits'],
            'inactive rate' => [[], ['active' => false], 'rate'],
            'expired rate' => [[], ['ends_at' => '2000-01-01'], 'rate'],
            'future rate' => [[], ['starts_at' => '2099-01-01'], 'rate'],
            'wrong service' => [[], ['service_type' => 'airtime'], 'rate'],
            'wrong network' => [[], ['network' => 'airtel'], 'rate'],
            'invalid percentage' => [[], ['value' => 100], 'positive'],
        ];
    }

    #[DataProvider('unavailableCases')]
    public function test_catalog_quote_and_submission_share_unavailability(array $networkChanges, array $rateChanges, string $reason): void
    {
        $network = $this->mtn();
        $network->update($networkChanges);
        $this->rate()->update($rateChanges);
        $this->actingAs($this->user());
        $catalog = $this->getJson('/api/customer/airtime-to-cash/networks')->assertOk();
        $entry = collect($catalog->json('data'))->firstWhere('id', $network->id);
        $this->assertFalse($entry['available']);
        $this->assertStringContainsString($reason, $entry['reason']);
        $this->getJson('/api/vtu/airtimeToCash/discount?network_id='.$network->id.'&amount=500')
            ->assertUnprocessable()->assertJsonPath('data.available', false);
        $this->postJson('/api/customer/airtime-to-cash', ['network_id' => $network->id, 'amount' => 500,
            'sender_phone' => '08012345678'])->assertUnprocessable();
        $this->assertDatabaseCount('airtime_to_cash_requests', 0);
    }

    public function test_missing_rate_blocks_submission_and_catalog_rechecks_rate_changes(): void
    {
        $network = $this->mtn();
        $this->actingAs($this->user());
        $this->getJson('/api/customer/catalog/networks')->assertOk()->assertJsonPath('data.0.airtime_to_cash_available', false);
        $this->postJson('/api/customer/airtime-to-cash', ['network_id' => $network->id, 'amount' => 500,
            'sender_phone' => '08012345678'])->assertUnprocessable();
        $rate = $this->rate();
        $this->getJson('/api/customer/catalog/networks')->assertOk()->assertJsonPath('data.0.airtime_to_cash_available', true);
        $rate->update(['ends_at' => '2000-01-01']);
        $this->getJson('/api/customer/catalog/networks')->assertOk()->assertJsonPath('data.0.airtime_to_cash_available', false);
    }

    public function test_changed_quote_and_invalid_amount_are_rejected(): void
    {
        $network = $this->mtn();
        $this->rate();
        $this->actingAs($this->user());
        $this->postJson('/api/customer/airtime-to-cash', ['network_id' => $network->id, 'amount' => 500,
            'quoted_payout' => 490, 'sender_phone' => '08012345678'])->assertUnprocessable()
            ->assertJsonPath('message', 'The conversion rate changed. Review a fresh quote before submitting.');
        foreach ([0, 99, 50001] as $amount) {
            $this->getJson('/api/vtu/airtimeToCash/discount?network_id='.$network->id.'&amount='.$amount)->assertUnprocessable();
        }
    }

    public function test_ambiguous_legacy_name_and_duplicate_rates_are_not_arbitrarily_selected(): void
    {
        $network = $this->mtn();
        $this->mtn();
        $this->rate();
        $this->actingAs($this->user());
        $this->getJson('/api/vtu/airtimeToCash/discount?network=mtn&amount=500')->assertUnprocessable();
        $this->getJson('/api/vtu/airtimeToCash/discount?network_id='.$network->id.'&amount=500')->assertOk();
        $this->rate();
        $this->getJson('/api/vtu/airtimeToCash/discount?network_id='.$network->id.'&amount=500')->assertUnprocessable();
    }

    public function test_configuration_saves_network_and_rate_atomically_with_permission(): void
    {
        $network = $this->mtn(false);
        $this->actingAs($this->admin());
        $this->getJson('/api/admin/airtime-to-cash/configuration')->assertOk()->assertJsonPath('data.0.rate', null);
        $this->putJson('/api/admin/airtime-to-cash/configuration/'.$network->id, $this->config())
            ->assertOk()->assertJsonPath('data.availability.available', true)->assertJsonPath('data.rate.value', '5.00');
        $this->assertTrue($network->fresh()->airtime_to_cash_active);
        $this->getJson('/api/admin/airtime-to-cash')->assertOk();
        $this->putJson('/api/admin/airtime-to-cash/configuration/'.$network->id, $this->config(['rate_value' => 100]))->assertUnprocessable();
        $this->assertEquals(5, Discount::first()->value);
    }

    public static function invalidConfigs(): array
    {
        return [[['destination_number' => null]], [['min_amount' => 0]], [['max_amount' => 50]],
            [['rate_type' => null, 'rate_value' => null]], [['rate_active' => false]],
            [['ends_at' => '2000-01-01']], [['starts_at' => '2099-01-01']],
            [['rate_type' => 'fixed', 'rate_value' => 100]]];
    }

    #[DataProvider('invalidConfigs')]
    public function test_cannot_enable_incomplete_configuration(array $changes): void
    {
        $network = $this->mtn(false);
        $this->actingAs($this->admin());
        $this->putJson('/api/admin/airtime-to-cash/configuration/'.$network->id, $this->config($changes))->assertUnprocessable();
        $this->assertFalse($network->fresh()->airtime_to_cash_active);
        $this->assertDatabaseCount('discounts', 0);
    }

    public function test_configuration_requires_admin_permission(): void
    {
        $network = $this->mtn(false);
        $this->actingAs($this->user());
        $this->getJson('/api/admin/airtime-to-cash/configuration')->assertForbidden();
        $this->putJson('/api/admin/airtime-to-cash/configuration/'.$network->id, $this->config())->assertForbidden();
    }

    public function test_fixed_rate_and_network_specific_over_global_rate(): void
    {
        $network = $this->mtn();
        $this->rate()->update(['discount_type' => 'fixed', 'value' => 25]);
        Discount::create(['name' => 'Default', 'service_type' => 'airtimeToCash', 'network' => null,
            'discount_type' => 'percentage', 'value' => 10, 'active' => true]);
        $this->actingAs($this->user())->getJson('/api/vtu/airtimeToCash/discount?network_id='.$network->id.'&amount=500')
            ->assertOk()->assertJsonPath('data.payout_amount', 475)->assertJsonPath('data.rate_type', 'fixed');
    }

    public function test_generic_network_writer_cannot_bypass_configuration_validation(): void
    {
        $network = $this->mtn(false);
        $admin = $this->admin();
        $permission = Permission::firstOrCreate(['slug' => 'settings'], ['name' => 'Settings']);
        $admin->role->permissions()->attach($permission);
        $this->actingAs($admin);
        $this->putJson('/api/table/networks/'.$network->id, ['airtime_to_cash_active' => true])->assertUnprocessable();
        $this->assertFalse($network->fresh()->airtime_to_cash_active);
        $this->putJson('/api/table/networks/'.$network->id, ['active' => false])->assertOk();
        $this->assertFalse($network->fresh()->active);
    }

    public function test_rate_changes_do_not_reprice_existing_pending_requests(): void
    {
        $network = $this->mtn();
        $rate = $this->rate();
        $this->actingAs($this->user());
        $created = $this->postJson('/api/customer/airtime-to-cash', ['network_id' => $network->id, 'amount' => 500,
            'quoted_payout' => 475, 'sender_phone' => '08012345678'])->assertCreated();
        $rate->update(['value' => 10]);
        $reviewer = $this->admin();
        $settled = app(AirtimeToCashSettlementService::class)->settle($created->json('data.id'), (string) $reviewer->id);
        $this->assertEquals(475, $settled->payout_amount);
        $this->assertEquals(475, $settled->payoutTransaction->amount);
    }

    public function test_destination_change_is_rejected_before_creating_request(): void
    {
        $network = $this->mtn();
        $this->rate();
        $this->actingAs($this->user())->postJson('/api/customer/airtime-to-cash', [
            'network_id' => $network->id, 'amount' => 500, 'quoted_payout' => 475,
            'quoted_destination_number' => '08099999999', 'sender_phone' => '08012345678',
        ])->assertUnprocessable()->assertJsonPath('message', 'The destination number changed. Review a fresh quote before transferring airtime.');
        $this->assertDatabaseCount('airtime_to_cash_requests', 0);
    }

    public function test_wrong_id_never_falls_back_to_a_valid_display_name(): void
    {
        $this->mtn();
        $this->rate();
        $this->actingAs($this->user());
        $this->getJson('/api/vtu/airtimeToCash/discount?network_id=999999&network=mtn&amount=500')->assertUnprocessable();
        $this->postJson('/api/customer/airtime-to-cash', ['network_id' => 999999, 'network' => 'mtn',
            'amount' => 500, 'sender_phone' => '08012345678'])->assertUnprocessable();
    }

    public function test_staff_without_conversion_permission_cannot_manage_configuration(): void
    {
        $admin = $this->admin();
        $admin->role->permissions()->detach();
        $this->actingAs($admin)->getJson('/api/admin/airtime-to-cash/configuration')->assertForbidden();
    }
    public function test_duplicate_manual_submission_with_same_key_creates_one_review_request(): void
    {
        $network = $this->mtn();
        $this->rate();
        $payload = ['network_id' => $network->id, 'amount' => 100, 'sender_phone' => '08012345678',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid()];
        $this->actingAs($this->user());
        $first = $this->postJson('/api/customer/airtime-to-cash', $payload)->assertCreated();
        $second = $this->postJson('/api/customer/airtime-to-cash', $payload)->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('airtime_to_cash_requests', 1);
        $this->assertDatabaseCount('transactions', 0);
        $this->postJson('/api/customer/airtime-to-cash', [...$payload, 'amount' => 200])->assertUnprocessable();
    }

}
