<?php

namespace App\Services\AirtimeToCash;

use App\Models\AirtimeToCashProviderSetting;
use App\Models\AirtimeToCashRequest;
use App\Services\AirtimeToCash\Providers\AutomationProvider;
use App\Services\AirtimeToCash\Providers\TwoFastProvider;

final class AirtimeToCashProviderManager
{
    public function get(string $key): AirtimeToCashProviderInterface
    {
        return match ($key) {
            'airtime_to_cash_automation' => app(AutomationProvider::class),
            '2fast' => app(TwoFastProvider::class),
            default => throw new \DomainException('Conversion provider is not configured.'),
        };
    }

    public function settings(): array
    {
        $saved = AirtimeToCashProviderSetting::all()->keyBy('provider');
        $result = [];
        foreach (config('airtime_to_cash.providers') as $key => $config) {
            $adapter = $this->get((string) $key);
            $result[] = ['key' => (string) $key, 'name' => $config['name'],
                'enabled' => (bool) ($saved->get($key)?->enabled ?? $config['enabled']),
                'priority' => $saved->get($key)?->priority ?? $config['priority'],
                'configured' => ! empty($config['token']), 'networks' => $adapter->networks(), 'capabilities' => $adapter->capabilities(),
                'health' => 'not_checked']; // Never contact providers to render config.
        }
        usort($result, fn ($a, $b) => [$a['priority'], $a['key']] <=> [$b['priority'], $b['key']]);

        return $result;
    }

    public function modeAvailable(): bool
    {
        return config('airtime_to_cash.provider_mode_enabled') && (app()->environment('testing') || config('airtime_to_cash.live_calls_enabled'));
    }

    public function select(string $network, float $amount): AirtimeToCashProviderInterface
    {
        if (! $this->modeAvailable()) {
            throw new \DomainException('Automated conversion is currently disabled.');
        }
        foreach ($this->settings() as $setting) {
            $limit = $setting['networks'][$network] ?? null;
            if (! $setting['enabled'] || ! $setting['configured'] || ! $limit) {
                continue;
            }
            if ($amount < ($limit['min'] ?? 1) || $amount > $limit['max'] || floor($amount) !== $amount) {
                continue;
            }

            return $this->get($setting['key']);
        }
        throw new \DomainException('Automated conversion is unavailable for this network or amount. Choose manual conversion before starting.');
    }

    public function bound(AirtimeToCashRequest $request): AirtimeToCashProviderInterface
    {
        if ($request->processing_mode !== 'provider' || ! $request->provider) {
            throw new \DomainException('Not an automated conversion.');
        }

        // Deliberately ignore current priority; existing sessions never fail over.
        return $this->get($request->provider);
    }
}
