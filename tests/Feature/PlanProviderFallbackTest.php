<?php

use App\Classes\Vendor\Providers\Adex;
use App\Classes\Vendor\Providers\Ogdams;
use App\Classes\VTUServices\VTUServiceFactory;
use App\Models\AirtimePlan;
use App\Models\CablePlan;
use App\Models\DataPlan;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function fallbackVendor(string $name, string $subCategory): Vendor
{
    return Vendor::create([
        'name' => $name,
        'sub_category' => $subCategory,
        'base_url' => 'https://' . strtolower(str_replace(' ', '-', $name)) . '.test',
        'active' => true,
    ]);
}

function attachFallback(object $plan, Vendor $primary, Vendor $fallback, ?string $primaryPlanId = null, ?string $fallbackPlanId = null): void
{
    DB::table('providerables')->insert([
        'provider_id' => $primary->id,
        'fallback_provider_id' => $fallback->id,
        'providerable_id' => $plan->id,
        'providerable_type' => get_class($plan),
        'server_id' => $primaryPlanId,
        'fallback_server_id' => $fallbackPlanId,
        'cost_price' => 0,
        'margin_value' => 0,
        'margin_type' => 'fiat',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('data fallback resolves the backup vendor and its own upstream plan id', function () {
    $primary = fallbackVendor('Primary Adex', 'adex');
    $fallback = fallbackVendor('Ogdams', 'simhost');
    $plan = DataPlan::create([
        'network' => 'mtn',
        'plan_name' => '1',
        'plan_size' => 'GB',
        'plan_type' => 'GIFTING',
        'validity' => '30 days',
        'active' => true,
    ]);
    attachFallback($plan, $primary, $fallback, 'primary-10', 'backup-99');

    $handler = VTUServiceFactory::makeFallback('data', 'GIFTING', 'mtn', $plan->id, 500);

    expect($handler)->toBeInstanceOf(Ogdams::class)
        ->and($handler->providerId())->toBe($fallback->id)
        ->and($handler->formatPayload('data', [
            'data_plan' => $plan->id,
            'network' => 'mtn',
            'phone' => '08000000000',
            'tx_ref' => 'fallback-ref',
        ])['planId'])->toBe('backup-99');
});

test('airtime and cable expose their configured fallback handlers', function () {
    $primary = fallbackVendor('Primary', 'adex');
    $fallback = fallbackVendor('Backup', 'adex');

    $airtime = AirtimePlan::create([
        'name' => 'mtn',
        'category' => 'vtu',
        'type' => 'airtime',
        'active' => true,
    ]);
    attachFallback($airtime, $primary, $fallback);

    $cable = CablePlan::create([
        'cable_network' => 'dstv',
        'plan_name' => 'Compact',
        'active' => true,
    ]);
    attachFallback($cable, $primary, $fallback, 'compact-primary', 'compact-backup');

    $airtimeHandler = VTUServiceFactory::makeFallback('airtime', 'vtu', 'mtn', null, 100);
    $cablePrimary = VTUServiceFactory::make('cable', 'cable', null, $cable->id, 'dstv', 1000);
    $cableFallback = VTUServiceFactory::makeFallback('cable', 'cable', null, $cable->id, 1000);

    expect($airtimeHandler)->toBeInstanceOf(Adex::class)
        ->and($airtimeHandler->providerId())->toBe($fallback->id)
        ->and($cablePrimary->providerId())->toBe($primary->id)
        ->and($cableFallback->providerId())->toBe($fallback->id);
});
