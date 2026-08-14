<?php

namespace App\Http\Controllers;

use App\Models\DataPlan;
use App\Models\Network;
use App\Support\PerformanceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PublicCatalogController extends Controller
{
    private const CUSTOMER_PLAN_TYPES = [
        'STANDARD',
        'SME',
        'GIFTING',
        'CG',
        'DATA SHARE',
        'AWOOF',
    ];

    public function dataPlans(): JsonResponse
    {
        $key = PerformanceCache::catalogVersionedKey('public-data-plans-v2', ['role' => 'basic']);
        $plans = Cache::get($key);
        $cacheHit = $plans !== null;

        if (! $cacheHit) {
            $activeNetworks = Network::query()
                ->where('active', true)
                ->pluck('name')
                ->map(fn (string $name) => strtolower($name))
                ->flip();

            $columns = [
                'id', 'network', 'plan_name', 'plan_size', 'plan_type',
                'network_type_id', 'validity', 'active', 'pricing',
            ];
            foreach (['basic_price', 'user_price'] as $legacyColumn) {
                if (Schema::hasColumn('data_plans', $legacyColumn)) {
                    $columns[] = $legacyColumn;
                }
            }

            $plans = DataPlan::query()
                ->select($columns)
                ->where('active', true)
                ->where(function ($query) {
                    $query->whereNull('is_draft')->orWhere('is_draft', false);
                })
                ->whereHas('networkType', fn ($query) => $query
                    ->where('service_type', 'data')
                    ->where('active', true))
                ->with(['providers:id', 'networkType:id,name,service_type,active'])
                ->orderBy('network')
                ->orderBy('sort_order')
                ->orderBy('plan_name')
                ->get()
                ->filter(function (DataPlan $plan) use ($activeNetworks) {
                    $type = strtoupper(trim((string) ($plan->networkType?->name ?? $plan->plan_type)));

                    return $activeNetworks->has(strtolower($plan->network))
                        && in_array($type, self::CUSTOMER_PLAN_TYPES, true);
                })
                ->map(function (DataPlan $plan) {
                    $price = $plan->priceForRoleKeys(['basic', 'user']);

                    if ($price === null || $price <= 0) {
                        return null;
                    }

                    return [
                        'network' => $plan->network,
                        'plan_name' => $plan->plan_name.$plan->plan_size,
                        'amount' => (string) $plan->plan_name,
                        'unit' => strtoupper((string) $plan->plan_size),
                        'validity' => $plan->validity,
                        'plan_type' => strtoupper(trim((string) ($plan->networkType?->name ?? $plan->plan_type))),
                        // Decimal string avoids leaking PHP's binary float
                        // representation (e.g. 1246.960000000000036...) into
                        // this customer-facing money API.
                        'selling_price' => number_format($price, 2, '.', ''),
                    ];
                })
                ->filter()
                ->values()
                ->all();

            Cache::put($key, $plans, now()->addMinutes(10));
        }

        $response = response()->json([
            'message' => 'successful',
            'success' => true,
            'data' => $plans,
            'type' => 'success',
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
        $response->headers->set('X-Cache', $cacheHit ? 'HIT' : 'MISS');
        $response->headers->set('Cache-Control', 'public, no-cache, must-revalidate');

        return $response;
    }
}
