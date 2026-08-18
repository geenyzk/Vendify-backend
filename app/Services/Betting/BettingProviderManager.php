<?php

namespace App\Services\Betting;

use App\Contracts\BettingProviderInterface;
use App\Models\Vendor;

class BettingProviderManager
{
    public function resolve(): BettingProviderInterface
    {
        $configured = strtolower((string) config('betting.provider', 'vtu_ng'));

        if (! in_array($configured, ['vtu_ng', 'vtung'], true)) {
            throw new \RuntimeException("Unsupported betting gateway [{$configured}].");
        }

        $vendor = Vendor::query()->where(function ($query) {
            $query->where('sub_category', 'vtu_ng')
                ->orWhereRaw('LOWER(REPLACE(REPLACE(name, ".", ""), " ", "")) = ?', ['vtung']);
        })->first();

        if (! $vendor) {
            if (! config('services.vtu_ng.api_token')
                && (! config('services.vtu_ng.username') || ! config('services.vtu_ng.password'))) {
                throw new \RuntimeException('Configure a VTU.ng provider or VTU.ng environment credentials before synchronizing betting companies.');
            }
            $vendor = new Vendor(['name' => 'VTU.ng', 'sub_category' => 'vtu_ng', 'base_url' => config('services.vtu_ng.base_url'), 'active' => true]);
        }
        if (! $vendor->active) {
            throw new \RuntimeException('Activate the configured VTU.ng provider before synchronizing betting companies.');
        }
        $hasToken = $vendor->api_key || config('services.vtu_ng.api_token');
        $hasLogin = ($vendor->username || config('services.vtu_ng.username'))
            && ($vendor->password || config('services.vtu_ng.password'));
        if (! $hasToken && ! $hasLogin) {
            throw new \RuntimeException('Complete the VTU.ng username/password or API token before synchronizing betting companies.');
        }

        return new VtuNgBettingProvider($vendor);
    }
}
