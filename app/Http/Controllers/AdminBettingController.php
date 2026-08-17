<?php

namespace App\Http\Controllers;

use App\Models\BettingProvider;
use App\Models\BettingSetting;
use App\Models\Transaction;
use App\Services\Betting\BettingProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminBettingController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success([
            'enabled' => BettingSetting::current()->enabled,
            'upstream_provider' => config('betting.provider'),
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
        $items = $manager->resolve()->supportedBillers();
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
