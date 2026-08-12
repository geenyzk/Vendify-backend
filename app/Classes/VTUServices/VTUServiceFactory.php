<?php

namespace App\Classes\VTUServices;

use App\Classes\Vendor\Providers\SimVending;
use App\Classes\Vendor\VendorFactory;
use App\Models\AirtimePlan;
use App\Models\CablePlan;
use App\Models\DataPlan;
use App\Models\ServiceRoute;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VTUServiceFactory
{
    static function make ($service='', $sub="", $network=null, $planId=null, $routeKey=null, ?float $amount=null) {
        $provider = self::resolveProvider($service, $sub, $network, $planId, $routeKey, $amount);

        if (!$provider) {
            // Return null rather than handing a null into VendorFactory::make()
            // (whose Vendor type hint would throw a TypeError straight out of a
            // live purchase). Callers check for a falsy handler and surface a
            // clean "unconfigured service" response instead.
            Log::warning('VTUServiceFactory: no vendor resolved for request', [
                'service' => $service,
                'sub'     => $sub,
                'network' => $network,
            ]);
            return null;
        }

        return match ($service) {
                "airtime"=> VendorFactory::make($provider),
                "data"=> VendorFactory::make($provider),
                "cable"=> VendorFactory::make($provider),
                "electricity"=> VendorFactory::make($provider),
                "airtimeToCash"=> VendorFactory::make($provider),
                "exam"=> VendorFactory::make($provider),
                default => null,
        } ;
    }

    /**
     * Build the explicitly configured backup handler for a plan. Unlike the
     * normal precedence chain, this never guesses another vendor: failover is
     * attempted only when an admin selected one in the plan create/edit form.
     */
    public static function makeFallback($service = '', $sub = '', $network = null, $planId = null, ?float $amount = null, array $excludeProviderIds = [])
    {
        $provider = null;

        if ($service === 'data' && $planId) {
            $provider = DataPlan::find($planId)?->resolveFallbackVendor($excludeProviderIds);
            // Native multi-provider mappings take precedence over the legacy
            // JSON fallback slots. Select only explicit, enabled, available
            // mappings in admin priority order.
            if (Schema::hasColumn('providerables', 'priority')) {
                $providerId = DB::table('providerables')
                    ->join('providers', 'providers.id', '=', 'providerables.provider_id')
                    ->where('providerables.providerable_id', $planId)
                    ->where('providerables.providerable_type', DataPlan::class)
                    ->where('providerables.provider_enabled', true)
                    ->where('providerables.provider_available', true)
                    ->where('providers.active', true)
                    ->when($excludeProviderIds !== [], fn ($query) => $query->whereNotIn('providerables.provider_id', $excludeProviderIds))
                    ->orderBy('providerables.priority')
                    ->value('providerables.provider_id');
                $provider = $providerId ? Vendor::find($providerId) : $provider;
            }
        } elseif ($service === 'cable' && $planId) {
            $provider = CablePlan::find($planId)?->resolveFallbackVendor($excludeProviderIds);
        } elseif ($service === 'airtime' && $network) {
            $plans = AirtimePlan::where('name', $network)->where('active', true)->get();
            $plan = $plans->first(fn ($candidate) => ($candidate->category ?: 'vtu') === $sub)
                ?? $plans->first();
            $provider = $plan?->resolveFallbackVendor($excludeProviderIds);
        }

        $provider = self::usable($provider, $service, $network, $planId, $amount);

        return $provider ? VendorFactory::make($provider) : null;
    }

    /**
     * Which Vendor fulfils this request.
     *
     * Airtime prefers the provider configured on the specific Airtime Plan
     * (Products > Airtime & Data create/edit) so an admin can route a given
     * network/category to a chosen vendor. When no plan-specific provider is
     * set — and for every other service — it falls back to the Stock Vending
     * assignment keyed by category/plan type (the pre-existing behaviour).
     *
     * Every layer passes through usable(): when a layer resolves the SIM
     * Vending vendor but no physical SIM can actually fulfil the vend right
     * now, that layer yields null and resolution falls through to the next —
     * the auto-failover that keeps purchases flowing to API providers while
     * devices are offline or SIM stock is depleted.
     */
    private static function resolveProvider($service, $sub, $network, $planId = null, $routeKey = null, ?float $amount = null): ?Vendor
    {
        if ($service === 'airtime' && $network) {
            $vendor = self::usable(self::airtimePlanVendor($network, $sub), $service, $network, $planId, $amount);
            if ($vendor) {
                return $vendor;
            }
        }

        // Data mirrors airtime: prefer the provider explicitly attached to the
        // specific Data Plan (the create/edit form's custom-provider toggle,
        // stored on the providerables pivot). Only when the plan has none do we
        // fall back to the routing rules below.
        if ($service === 'data' && $planId) {
            $vendor = self::usable(DataPlan::find($planId)?->resolveVendor(), $service, $network, $planId, $amount);
            if ($vendor) {
                return $vendor;
            }
        }

        if ($service === 'cable' && $planId) {
            $vendor = self::usable(CablePlan::find($planId)?->resolveVendor(), $service, $network, $planId, $amount);
            if ($vendor) {
                return $vendor;
            }
        }

        // Dynamic Service Routing (admin: APIs → Service Routing): the vendor an
        // admin assigned to this service's specific dimension — data plan_type,
        // airtime category, cable network, disco, or the service itself for
        // singletons. Data is stored, not columns, so new categories are
        // routable with no migration. Unset keys fall through to the legacy
        // Stock Vending assignment so existing behaviour is preserved.
        $vendor = self::usable(ServiceRoute::resolveVendor($service, $routeKey ?? $sub), $service, $network, $planId, $amount);
        if ($vendor) {
            return $vendor;
        }

        return self::usable(Vendor::provider($sub ?? $service)->first(), $service, $network, $planId, $amount);
    }

    /**
     * A resolved vendor, unless it is the SIM Vending vendor and no eligible
     * SIM can serve this vend right now — then null, so the caller's
     * precedence chain continues to the next layer. Runs before any funds
     * are reserved, so an ineligible SIM route costs the customer nothing.
     */
    private static function usable(?Vendor $vendor, string $service, ?string $network, $planId, ?float $amount): ?Vendor
    {
        if ($vendor
            && $vendor->sub_category === 'simvend'
            && !SimVending::canServe($service, $network, $amount, $planId)) {
            Log::info('SimVending cannot serve this vend — falling through to next provider layer', [
                'service' => $service,
                'network' => $network,
            ]);
            return null;
        }

        return $vendor;
    }

    /**
     * The Vendor attached to the active Airtime Plan for this network/category,
     * or null if none is configured. Mirrors ServiceRequest / the buy-airtime
     * page: a plan with no category is treated as "vtu", and if no plan matches
     * the exact category the network's first active plan is used.
     */
    private static function airtimePlanVendor($network, $category): ?Vendor
    {
        $plans = AirtimePlan::where('name', $network)->where('active', true)->get();
        if ($plans->isEmpty()) {
            return null;
        }

        $plan = $plans->first(fn ($p) => ($p->category ?: 'vtu') === $category)
            ?? $plans->first();

        return $plan?->resolveVendor();
    }
}
