<?php

namespace App\Services\Betting;

use App\Contracts\BettingProviderInterface;
use App\Models\Vendor;

class BettingProviderManager
{
    public function resolve(): BettingProviderInterface
    {
        $configured = strtolower((string) config('betting.provider', 'vtpass'));

        if ($configured !== 'vtpass') {
            throw new \RuntimeException("Unsupported betting gateway [{$configured}].");
        }

        $vendor = Vendor::query()->where('sub_category', 'vtpass')->first();

        if (! $vendor) {
            throw new \RuntimeException('Configure a VTpass provider before synchronizing betting companies.');
        }
        if (! $vendor->active) {
            throw new \RuntimeException('Activate the configured VTpass provider before synchronizing betting companies.');
        }
        if (empty($vendor->base_url) || empty($vendor->api_key) || empty($vendor->public_key)) {
            throw new \RuntimeException('Complete the VTpass base URL, API key, and public key before synchronizing betting companies.');
        }

        return new VtpassBettingProvider($vendor);
    }
}
