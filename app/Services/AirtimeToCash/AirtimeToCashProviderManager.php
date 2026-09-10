<?php

namespace App\Services\AirtimeToCash;

use App\Models\AirtimeToCashRequest;
use App\Services\AirtimeToCash\Providers\AutomationProvider;
use App\Services\AirtimeToCash\Providers\TwoFastProvider;

final class AirtimeToCashProviderManager
{
    public function __construct(private AirtimeToCashProviderConfiguration $configuration) {}

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
        $result = [];
        foreach (config('airtime_to_cash.providers') as $key => $config) {
            $adapter = $this->get((string) $key);
            $safe = $this->configuration->safeMetadata((string) $key);
            $result[] = [
                'key' => (string) $key,
                'name' => $config['name'],
                ...$safe,
                'credentials' => $adapter->credentialRequirements($safe['configured']),
                'networks' => $adapter->networks(),
                'capabilities' => $adapter->capabilities(),
                'automatic_failover' => false,
            ]; // Rendering configuration never contacts a provider.
        }
        usort($result, fn ($a, $b) => [$a['priority'], $a['key']] <=> [$b['priority'], $b['key']]);

        return $result;
    }

    public function modeAvailable(): bool
    {
        return $this->configuration->providerModeEnabled()
            && $this->configuration->liveCallsEnabled();
    }

    public function availableForNetwork(string $network, float $min, float $max): bool
    {
        if (! $this->modeAvailable()) {
            return false;
        }
        foreach ($this->settings() as $setting) {
            $limit = $setting['networks'][$network] ?? null;
            if ($setting['enabled'] && $setting['configured'] && $limit
                && ceil(max($min, $limit['min'] ?? 1)) <= floor(min($max, $limit['max']))) {
                return true;
            }
        }
        return false;
    }

    public function testConnection(string $provider): ProviderHealthResult
    {
        $resolved = $this->configuration->resolved($provider);
        if (empty($resolved['token'])) {
            throw new \DomainException('Add a provider credential before testing the connection.');
        }
        $result = $this->get($provider)->healthCheck();
        $this->configuration->recordHealth($provider, $result);

        return $result;
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
