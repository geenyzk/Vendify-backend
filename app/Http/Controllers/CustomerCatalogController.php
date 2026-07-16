<?php

namespace App\Http\Controllers;

use App\Models\DataPlan;
use App\Models\Network;
use App\Support\PerformanceCache;
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
                ->with(['networkTypes' => fn ($query) => $query->select([
                    'network_types.id', 'network_types.name', 'network_types.service_type', 'network_types.active',
                ])])
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
        return $response;
    }

    public function dataPlans(Request $request): JsonResponse
    {
        $role = $request->user()?->role?->name ?? 'user';
        $key = PerformanceCache::catalogVersionedKey('customer-data-plans', ['role' => $role]);
        $plans = Cache::get($key);
        $cacheHit = $plans !== null;
        if (! $cacheHit) {
            $columns = ['id', 'network', 'plan_name', 'plan_size', 'plan_type', 'validity', 'active', 'pricing'];
            $legacyPriceColumn = $role . '_price';
            if (Schema::hasColumn('data_plans', $legacyPriceColumn)) {
                $columns[] = $legacyPriceColumn;
            }

            return DataPlan::query()
                ->select($columns)
                ->where('active', true)
                ->where(function ($query) {
                    $query->whereNull('is_draft')->orWhere('is_draft', false);
                })
                ->with('providers:id,name')
                ->orderBy('network')->orderBy('sort_order')->orderBy('plan_name')
                ->get()
                ->map(fn (DataPlan $plan) => [
                    'id' => $plan->id,
                    'network' => $plan->network,
                    'plan_name' => $plan->plan_name,
                    'plan_size' => $plan->plan_size,
                    'plan_type' => $plan->plan_type,
                    'plan' => $plan->plan,
                    'validity' => $plan->validity,
                    'active' => true,
                    'price' => $plan->price,
                ])->values()->all();
            Cache::put($key, $plans, now()->addMinutes(10));
        }

        $response = $this->success($plans);
        $response->headers->set('X-Cache', $cacheHit ? 'HIT' : 'MISS');
        return $response;
    }
}
