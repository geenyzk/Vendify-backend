<?php

namespace App\Classes\VTUServices;

use App\Classes\Vendor\VendorFactory;
use App\Models\AirtimePlan;
use App\Models\DataPlan;
use App\Models\ServiceRoute;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;

class VTUServiceFactory
{
    static function make ($service='', $sub="", $network=null, $planId=null, $routeKey=null) {
        $provider = self::resolveProvider($service, $sub, $network, $planId, $routeKey);

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
     * Which Vendor fulfils this request.
     *
     * Airtime prefers the provider configured on the specific Airtime Plan
     * (Products > Airtime & Data create/edit) so an admin can route a given
     * network/category to a chosen vendor. When no plan-specific provider is
     * set — and for every other service — it falls back to the Stock Vending
     * assignment keyed by category/plan type (the pre-existing behaviour).
     */
    private static function resolveProvider($service, $sub, $network, $planId = null, $routeKey = null): ?Vendor
    {
        if ($service === 'airtime' && $network) {
            $vendor = self::airtimePlanVendor($network, $sub);
            if ($vendor) {
                return $vendor;
            }
        }

        // Data mirrors airtime: prefer the provider explicitly attached to the
        // specific Data Plan (the create/edit form's custom-provider toggle,
        // stored on the providerables pivot). Only when the plan has none do we
        // fall back to the routing rules below.
        if ($service === 'data' && $planId) {
            $vendor = DataPlan::find($planId)?->resolveVendor();
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
        $vendor = ServiceRoute::resolveVendor($service, $routeKey ?? $sub);
        if ($vendor) {
            return $vendor;
        }

        return Vendor::provider($sub ?? $service)->first();
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
