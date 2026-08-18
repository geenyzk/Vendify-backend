<?php

namespace App\Http\Controllers;

use App\Models\BettingProvider;
use App\Models\BettingSetting;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Services\Betting\BettingProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminBettingController extends Controller
{
    public function index(): JsonResponse
    {
        $gatewayType = strtolower((string) config('betting.provider', 'vtu_ng'));
        $gateway = Vendor::query()->where(function ($query) {
            $query->where('sub_category', 'vtu_ng')
                ->orWhereRaw('LOWER(REPLACE(REPLACE(name, ".", ""), " ", "")) = ?', ['vtung']);
        })->first();
        $environmentHasToken = (bool) config('services.vtu_ng.api_token');
        $environmentHasLogin = (bool) config('services.vtu_ng.username')
            && (bool) config('services.vtu_ng.password');
        $environmentConfigured = $environmentHasToken || $environmentHasLogin;
        $hasToken = (bool) ($gateway?->api_key ?: config('services.vtu_ng.api_token'));
        $hasLogin = (bool) ($gateway?->username ?: config('services.vtu_ng.username'))
            && (bool) ($gateway?->password ?: config('services.vtu_ng.password'));

        return $this->success([
            'enabled' => BettingSetting::current()->enabled,
            'upstream_provider' => $gatewayType,
            'gateway' => [
                'configured' => $gateway !== null || $environmentConfigured,
                'active' => $gateway ? (bool) $gateway->active : $environmentConfigured,
                'provider_id' => $gateway?->id,
                'name' => $gateway?->name ?? ($environmentConfigured ? 'VTU.ng (environment)' : null),
                'missing_credentials' => $hasToken || $hasLogin ? [] : ['username/password or api_token'],
            ],
            'providers' => BettingProvider::orderBy('name')->get(),
            'recent_transactions' => Transaction::where('transaction_type', 'betting_funding')->latest()->limit(25)->get(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $setting = BettingSetting::current();
        $setting->update($validated);

        return $this->success($setting);
    }

    public function updateProvider(Request $request, BettingProvider $bettingProvider): JsonResponse
    {
        $validated = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'verification_supported' => ['sometimes', 'boolean'],
            'minimum_amount' => ['sometimes', 'numeric', 'min:1'],
            'maximum_amount' => ['sometimes', 'numeric', 'gte:minimum_amount'],
            'flat_fee' => ['sometimes', 'numeric', 'min:0'],
            'percentage_fee' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);
        $bettingProvider->update($validated);

        return $this->success($bettingProvider->fresh());
    }

    public function sync(BettingProviderManager $manager): JsonResponse
    {
        try {
            $items = $manager->resolve()->supportedBillers();
        } catch (\RuntimeException $e) {
            return $this->fail([], $e->getMessage(), 422, 'provider_unavailable');
        }
        foreach ($items as $item) {
            BettingProvider::updateOrCreate(
                ['biller_id' => $item['biller_id']],
                [
                    'name' => $item['name'],
                    'slug' => Str::slug($item['name']),
                    'provider_code' => $item['biller_id'],
                    'minimum_amount' => max(1, $item['minimum_amount']),
                    'maximum_amount' => max($item['minimum_amount'], $item['maximum_amount']),
                    'metadata' => $item['metadata'],
                    // A sync never silently enables a newly discovered biller.
                    'active' => BettingProvider::where('biller_id', $item['biller_id'])->value('active') ?? false,
                ],
            );
        }

        return $this->success([
            'synced' => count($items),
            'providers' => BettingProvider::orderBy('name')->get(),
        ], count($items) ? 'Betting providers synchronized.' : 'No betting providers were returned by the upstream service.');
    }
}
