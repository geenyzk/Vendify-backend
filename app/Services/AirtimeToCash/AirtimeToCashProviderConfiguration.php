<?php

namespace App\Services\AirtimeToCash;

use App\Models\AirtimeToCashProviderSetting;
use App\Models\Setting;
use App\Support\AuditLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Support\Facades\DB;

final class AirtimeToCashProviderConfiguration
{
    public function definition(string $provider): array
    {
        $definition = config('airtime_to_cash.providers.'.$provider);
        if (! is_array($definition)) {
            throw new \DomainException('Conversion provider is not configured.');
        }

        return $definition;
    }

    public function resolved(string $provider): array
    {
        $definition = $this->definition($provider);
        $setting = AirtimeToCashProviderSetting::find($provider);

        try {
            $storedToken = $setting?->token;
        } catch (DecryptException) {
            throw new \DomainException('The stored provider credential could not be decrypted. Replace it before continuing.');
        }

        return [
            ...$definition,
            'base_url' => $setting?->base_url ?: $definition['base_url'],
            'token' => $storedToken ?: $definition['token'],
            'enabled' => $setting?->enabled ?? (bool) $definition['enabled'],
            'priority' => $setting?->priority ?? (int) $definition['priority'],
        ];
    }

    public function safeMetadata(string $provider): array
    {
        $definition = $this->definition($provider);
        $setting = AirtimeToCashProviderSetting::find($provider);
        $credentialError = false;

        try {
            $storedToken = $setting?->token;
        } catch (DecryptException) {
            $storedToken = null;
            $credentialError = true;
        }

        $configured = ! $credentialError && ! empty($storedToken ?: $definition['token']);
        try {
            $this->validatedBaseUrl((string) ($setting?->base_url ?: $definition['base_url']), $definition['allowed_host']);
        } catch (\DomainException) {
            $configured = false;
        }

        return [
            'base_url' => $setting?->base_url ?: $definition['base_url'],
            'enabled' => $setting?->enabled ?? (bool) $definition['enabled'],
            'priority' => $setting?->priority ?? (int) $definition['priority'],
            'configured' => $configured,
            'credential_error' => $credentialError,
            'credentials' => [[
                'key' => 'token',
                'label' => $definition['credential_label'],
                'secret' => true,
                'configured' => $configured,
            ]],
            'health' => $setting?->health_status ?? 'not_checked',
            'health_message' => $setting?->health_message,
            'last_health_check_at' => $setting?->last_health_check_at?->toIso8601String(),
        ];
    }

    public function runtime(): array
    {
        $setting = Setting::first();

        return [
            'mode_enabled' => $setting?->airtime_to_cash_provider_mode_enabled
                ?? (bool) config('airtime_to_cash.provider_mode_enabled'),
            'live_calls_enabled' => $setting?->airtime_to_cash_live_calls_enabled
                ?? (bool) config('airtime_to_cash.live_calls_enabled'),
        ];
    }

    public function providerModeEnabled(): bool
    {
        return $this->runtime()['mode_enabled'];
    }

    public function liveCallsEnabled(): bool
    {
        return $this->runtime()['live_calls_enabled'];
    }

    public function updateProvider(string $provider, array $input): AirtimeToCashProviderSetting
    {
        $definition = $this->definition($provider);
        $setting = AirtimeToCashProviderSetting::find($provider) ?? new AirtimeToCashProviderSetting([
            'provider' => $provider,
            'enabled' => (bool) $definition['enabled'],
            'priority' => (int) $definition['priority'],
        ]);
        $baseUrl = $this->validatedBaseUrl(
            (string) ($input['base_url'] ?? ($setting->base_url ?: $definition['base_url'])),
            $definition['allowed_host'],
        );

        $replacement = isset($input['token']) && is_string($input['token']) && trim($input['token']) !== ''
            ? trim($input['token']) : null;
        try {
            $existingToken = $setting->exists ? $setting->token : null;
        } catch (DecryptException) {
            $existingToken = null;
            if ($replacement === null) {
                throw new \DomainException('The stored provider credential could not be decrypted. Enter a replacement credential.');
            }
        }
        $effectiveToken = $replacement ?: $existingToken ?: $definition['token'];
        if ($input['enabled'] && empty($effectiveToken)) {
            throw new \DomainException('Add a provider credential before enabling this provider.');
        }

        $configurationChanged = $setting->base_url !== $baseUrl || $replacement !== null;
        $setting->fill([
            'enabled' => $input['enabled'],
            'priority' => $input['priority'],
            'base_url' => $baseUrl,
        ]);
        if ($replacement !== null) {
            try {
                $setting->token = $replacement;
            } catch (EncryptException) {
                throw new \DomainException('The provider credential could not be encrypted. No credential was saved.');
            }
        }
        if ($configurationChanged) {
            $setting->health_status = 'not_checked';
            $setting->health_message = null;
            $setting->last_health_check_at = null;
        }
        try {
            $setting->save();
        } catch (EncryptException) {
            throw new \DomainException('The provider credential could not be encrypted. No credential was saved.');
        }

        return $setting;
    }

    public function updateRuntime(bool $modeEnabled, bool $liveCallsEnabled): array
    {
        if ($liveCallsEnabled && ! $modeEnabled) {
            throw new \DomainException('Provider mode must be enabled before live provider calls can be enabled.');
        }
        if ($liveCallsEnabled && ! $this->hasEnabledConfiguredProvider()) {
            throw new \DomainException('Enable and configure at least one provider before enabling live calls.');
        }

        return DB::transaction(function () use ($modeEnabled, $liveCallsEnabled) {
            $setting = Setting::firstOrCreate([]);
            $old = $this->runtime();
            $setting->update([
                'airtime_to_cash_provider_mode_enabled' => $modeEnabled,
                'airtime_to_cash_live_calls_enabled' => $liveCallsEnabled,
            ]);
            $new = ['mode_enabled' => $modeEnabled, 'live_calls_enabled' => $liveCallsEnabled];
            AuditLogger::record(
                'airtime_to_cash_runtime_updated',
                subject: $setting,
                changes: [
                    'mode_enabled' => ['old' => $old['mode_enabled'], 'new' => $modeEnabled],
                    'live_calls_enabled' => ['old' => $old['live_calls_enabled'], 'new' => $liveCallsEnabled],
                ],
                description: 'Updated Airtime-to-Cash provider runtime settings.',
            );

            return $new;
        });
    }

    public function recordHealth(string $provider, ProviderHealthResult $result): void
    {
        $definition = $this->definition($provider);
        $setting = AirtimeToCashProviderSetting::firstOrNew(['provider' => $provider]);
        if (! $setting->exists) {
            $setting->enabled = (bool) $definition['enabled'];
            $setting->priority = (int) $definition['priority'];
        }
        $setting->health_status = $result->status();
        $setting->health_message = $result->message;
        $setting->last_health_check_at = now();
        $setting->saveQuietly();

        AuditLogger::record(
            'airtime_to_cash_provider_connection_tested',
            subject: $setting,
            changes: ['health' => $result->status()],
            description: 'Tested Airtime-to-Cash provider connection.',
        );
    }

    private function hasEnabledConfiguredProvider(): bool
    {
        foreach (array_keys(config('airtime_to_cash.providers')) as $provider) {
            $metadata = $this->safeMetadata((string) $provider);
            if ($metadata['enabled'] && $metadata['configured']) {
                return true;
            }
        }

        return false;
    }

    private function validatedBaseUrl(string $baseUrl, string $expectedHost): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $url = parse_url($baseUrl);
        if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== $expectedHost
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! empty($url['path']) || (isset($url['port']) && $url['port'] !== 443)) {
            throw new \DomainException('Use the provider’s official HTTPS base URL.');
        }

        return $baseUrl;
    }
}
