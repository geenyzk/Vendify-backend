<?php

namespace Database\Seeders;

use App\Models\AirtimePlan;
use App\Models\DataPlan;
use App\Models\NetworkType;
use App\Models\ServiceRoute;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Bootstraps SIM vending as the DEFAULT route for airtime and data:
 *
 *  1. Creates the single 'simvend' Vendor row the routing pipeline targets.
 *     It is deliberately NOT offered in the admin "add provider" form
 *     (VendorFactory::PROVIDER_META) — the fleet is managed under its own
 *     /admin/sim-vending surface.
 *  2. Points every airtime/data Service Routing key at it — but only keys
 *     with no assignment yet, so re-running never clobbers an admin's
 *     explicit routing choices. Per-plan provider pivots also still win
 *     (VTUServiceFactory precedence is unchanged), and when no SIM can
 *     serve a vend the request auto-fails-over to the next provider layer.
 *
 * Safe to run repeatedly: php artisan db:seed --class=SimVendingSeeder
 */
class SimVendingSeeder extends Seeder
{
    public function run(): void
    {
        $vendor = Vendor::firstOrCreate(
            ['sub_category' => 'simvend'],
            [
                'name' => 'SIM Vending',
                'active' => true,
                'identifier' => Str::lower(Str::random(16)),
                'webhook_access' => '0',
            ],
        );

        foreach ($this->routeKeys() as [$serviceType, $routeKey]) {
            $existing = ServiceRoute::where('service_type', $serviceType)
                ->where('route_key', $routeKey)
                ->first();

            // Only claim keys nobody routed yet — an admin's explicit
            // provider choice (even "unset on purpose" rows with a provider)
            // must survive a re-seed.
            if ($existing && $existing->provider_id) {
                continue;
            }

            ServiceRoute::updateOrCreate(
                ['service_type' => $serviceType, 'route_key' => $routeKey],
                ['provider_id' => $vendor->id],
            );
        }
    }

    /**
     * Every airtime/data routing key, enumerated the same way the admin
     * Service Routing screen does (ServiceRoutingController::airtimeKeys/
     * dataKeys) so the seeded defaults line up 1:1 with what admins see.
     * Cable/electricity/exam are untouched — SIMs can't serve them.
     *
     * @return array<array{0: string, 1: string}>
     */
    private function routeKeys(): array
    {
        $norm = fn (string $v): string => strtolower(str_replace(['_', '-'], ' ', trim($v)));

        $airtime = collect()
            ->merge(NetworkType::where('service_type', 'airtime')->pluck('name'))
            ->merge(AirtimePlan::query()->pluck('category')->map(fn ($c) => $c ?: 'vtu'));

        $data = collect()
            ->merge(NetworkType::where('service_type', 'data')->pluck('name'))
            ->merge(DataPlan::query()->whereNotNull('plan_type')->distinct()->pluck('plan_type'));

        $out = [];
        foreach (['airtime' => $airtime, 'data' => $data] as $serviceType => $keys) {
            $seen = [];
            foreach ($keys as $key) {
                $key = trim((string) $key);
                if ($key === '' || isset($seen[$norm($key)])) {
                    continue;
                }
                $seen[$norm($key)] = true;
                $out[] = [$serviceType, $key];
            }
        }

        return $out;
    }
}
