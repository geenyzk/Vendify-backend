<?php

namespace App\Http\Controllers;

use App\Models\DataPlan;
use App\Models\Network;
use App\Support\PerformanceCache;
use App\Support\ProviderPlanPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class CustomerCatalogController extends Controller
{
    public function networks(): JsonResponse
    {
        $key = PerformanceCache::catalogVersionedKey('customer-networks', []);
        $networks = Cache::get($key);
        $cacheHit = $networks !== null;
        if (! $cacheHit) {
            $networks = Network::query()
                ->select([
                    'id', 'name', 'active', 'airtime_to_cash_destination_number',
                    'airtime_to_cash_min', 'airtime_to_cash_max', 'airtime_to_cash_active',
                ])
                ->with(['networkTypes' => fn ($query) => $query
                    ->select([
                        'network_types.id', 'network_types.name', 'network_types.service_type', 'network_types.active',
                    ])
                    ->where('network_types.active', true)
                    ->where('network_network_type.active', true)])
                ->orderBy('name')
                ->get()
                ->map(fn (Network $network) => [
                    'id' => $network->id,
                    'name' => $network->name,
                    'active' => (bool) $network->active,
                    'airtime_to_cash_destination_number' => $network->airtime_to_cash_destination_number,
                    'airtime_to_cash_min' => $network->airtime_to_cash_min,
                    'airtime_to_cash_max' => $network->airtime_to_cash_max,
                    'airtime_to_cash_active' => (bool) $network->airtime_to_cash_active,
                    'network_types' => $network->networkTypes->map(fn ($type) => [
                        'id' => $type->id,
                        'name' => $type->name,
                        'service_type' => $type->service_type,
                        'active' => (bool) $type->active,
                        'pivot' => ['active' => (bool) $type->pivot->active],
                    ])->values(),
                ])->values()->all();
            Cache::put($key, $networks, now()->addMinutes(10));
        }

        $response = $this->success($networks);
        $response->headers->set('X-Cache', $cacheHit ? 'HIT' : 'MISS');
        // Application caching above is versioned and cheap; do not let the
        // browser independently reuse an old response after an admin edit.
        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
        $response->headers->set('Vary', 'Authorization, Cookie');

        return $response;
    }

    public function dataPlans(Request $request): JsonResponse
    {
        $role = $request->user()?->role?->name ?? 'user';
        $key = PerformanceCache::catalogVersionedKey('customer-data-plans', ['role' => $role]);
        $plans = Cache::get($key);
        $cacheHit = $plans !== null;
        if (! $cacheHit) {
            $columns = ['id', 'network', 'plan_name', 'plan_size', 'plan_type', 'network_type_id', 'auto_category_id', 'manual_category_id', 'validity', 'active', 'is_featured', 'pricing'];
            $legacyPriceColumn = $role.'_price';
            if (Schema::hasColumn('data_plans', $legacyPriceColumn)) {
                $columns[] = $legacyPriceColumn;
            }

            $plans = DataPlan::query()
                ->select($columns)
                ->customerVisible()
                ->whereHas('networkType', fn ($query) => $query
                    ->where('service_type', 'data')
                    ->where('active', true))
                ->with(['providers:id,name', 'networkType:id,name,service_type,active', 'autoCategory:id,name,slug,is_active', 'manualCategory:id,name,slug,is_active'])
                ->orderBy('network')->orderBy('sort_order')->orderBy('plan_name')
                ->get()
                ->map(function (DataPlan $plan) {
                    $providerPlan = $plan->providers->first()?->pivot?->provider_plan_name;
                    $presentation = ProviderPlanPresentation::from($providerPlan, $plan->validity);

                    return [
                        'id' => $plan->id,
                        'network' => $plan->network,
                        'plan_name' => $plan->plan_name,
                        'plan_size' => $plan->plan_size,
                        'plan_type' => $plan->networkType?->name ?? $plan->plan_type,
                        'plan' => $plan->plan,
                        'validity' => $plan->validity,
                        'provider_plan_name' => $presentation['original'],
                        'provider_plan_description' => $presentation['description'],
                        'provider_plan_parse_confident' => $presentation['confident'],
                        'active' => true,
                        'category' => $plan->effective_category?->name,
                        'category_slug' => $plan->effective_category?->slug,
                        'is_featured' => (bool) $plan->is_featured,
                        'price' => $plan->price === null ? null : (float) $plan->price,
                    ];
                })->values()->all();
            Cache::put($key, $plans, now()->addMinutes(10));
        }

        $response = response()->json([
            'message' => 'successful',
            'success' => true,
            'data' => $plans,
            'type' => 'success',
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
        $response->headers->set('X-Cache', $cacheHit ? 'HIT' : 'MISS');
        // The catalog version changes on every admin plan mutation. Force the
        // browser to revalidate so that version can take effect immediately.
        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
        $response->headers->set('Vary', 'Authorization, Cookie');

        return $response;
    }
}
