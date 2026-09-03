<?php

namespace Tests\Feature;

use App\Models\AirtimeToCashRequest;
use App\Models\Discount;
use App\Models\Network;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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
            ->assertJsonPath('message', 'Airtime to cash is not available for this network yet.');
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
}
