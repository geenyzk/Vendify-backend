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

        $vendor = Vendor::query()
            ->where('sub_category', 'vtpass')
            ->where('active', true)
            ->first();

        if (! $vendor) {
            throw new \RuntimeException('The configured betting gateway is unavailable.');
        }

        return new VtpassBettingProvider($vendor);
    }
}
