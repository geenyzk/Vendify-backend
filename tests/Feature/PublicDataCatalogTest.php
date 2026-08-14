<?php

namespace Tests\Feature;

use App\Models\DataPlan;
use App\Models\Network;
use App\Models\NetworkType;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicDataCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Network $network;

    private NetworkType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->network = Network::create(['name' => 'MTN', 'active' => true]);
        $this->type = NetworkType::create([
            'name' => DataPlan::STANDARD_TYPE,
            'service_type' => 'data',
            'active' => true,
        ]);
        $this->network->networkTypes()->attach($this->type, [
            'service_type' => 'data',
            'active' => true,
        ]);
    }

    private function createPlan(array $attributes = []): DataPlan
    {
        return DataPlan::create(array_merge([
            'network' => 'mtn',
            'plan_type' => DataPlan::STANDARD_TYPE,
            'network_type_id' => $this->type->id,
            'plan_name' => '1',
            'plan_size' => 'GB',
            'validity' => '30 Days',
            'active' => true,
            'is_draft' => false,
            'pricing' => ['basic' => 600, 'user' => 700],
        ], $attributes));
    }

    public function test_catalogue_is_public_and_uses_the_basic_customer_price(): void
    {
        $this->createPlan();

        $this->getJson('/api/public/catalog/data-plans')
            ->assertOk()
            ->assertJsonPath('data.0.network', 'mtn')
            ->assertJsonPath('data.0.plan_name', '1GB')
            ->assertJsonPath('data.0.amount', '1')
            ->assertJsonPath('data.0.unit', 'GB')
            ->assertJsonPath('data.0.validity', '30 Days')
            ->assertJsonPath('data.0.plan_type', 'STANDARD')
            ->assertJsonPath('data.0.selling_price', '600.00');
    }

    public function test_catalogue_excludes_inactive_draft_unpriced_and_unavailable_plans(): void
    {
        $visible = $this->createPlan();
        $this->createPlan(['plan_name' => '2', 'active' => false]);
        $this->createPlan(['plan_name' => '3', 'is_draft' => true]);
        $this->createPlan(['plan_name' => '4', 'pricing' => []]);

        $inactiveType = NetworkType::create([
            'name' => 'SME', 'service_type' => 'data', 'active' => false,
        ]);
        $this->createPlan([
            'plan_name' => '5', 'plan_type' => 'SME', 'network_type_id' => $inactiveType->id,
        ]);

        $response = $this->getJson('/api/public/catalog/data-plans')->assertOk();
        $plans = collect($response->json('data'));

        $this->assertCount(1, $plans);
        $this->assertSame($visible->plan_name.$visible->plan_size, $plans->first()['plan_name']);
    }

    public function test_catalogue_never_exposes_supplier_or_internal_fields(): void
    {
        $plan = $this->createPlan();
        $vendor = Vendor::create([
            'name' => 'CheapDataHub',
            'sub_category' => 'cheapdatahub',
            'base_url' => 'https://supplier.test',
            'active' => true,
        ]);
        DB::table('providerables')->insert([
            'provider_id' => $vendor->id,
            'providerable_id' => $plan->id,
            'providerable_type' => DataPlan::class,
            'external_plan_id' => 'supplier-secret-id',
            'server_id' => 'internal-server',
            'cost_price' => 400,
            'margin_value' => 0,
            'margin_type' => 'fiat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/public/catalog/data-plans')->assertOk();
        $row = $response->json('data.0');

        $this->assertSame([
            'network', 'plan_name', 'amount', 'unit', 'validity', 'plan_type', 'selling_price',
        ], array_keys($row));
        $this->assertStringNotContainsString('CheapDataHub', $response->getContent());
        $this->assertStringNotContainsString('supplier-secret-id', $response->getContent());
        $this->assertStringNotContainsString('cost_price', $response->getContent());
        $this->assertStringNotContainsString('server_id', $response->getContent());
    }

    public function test_catalogue_rejects_provider_derived_plan_types(): void
    {
        $providerType = NetworkType::create([
            'name' => 'VTU.ng', 'service_type' => 'data', 'active' => true,
        ]);
        $this->network->networkTypes()->attach($providerType, [
            'service_type' => 'data', 'active' => true,
        ]);
        $this->createPlan([
            'plan_type' => 'VTU.ng', 'network_type_id' => $providerType->id,
        ]);

        $this->getJson('/api/public/catalog/data-plans')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
