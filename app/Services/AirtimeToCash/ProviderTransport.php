<?php

namespace App\Services\AirtimeToCash;

use Illuminate\Support\Facades\Http;

final class ProviderTransport
{
    public function post(string $provider, string $path, #[\SensitiveParameter] array $payload, bool $protected = true): array
    {
        $config = config('airtime_to_cash.providers.'.$provider);
        $expectedHost = $provider === '2fast' ? '2fast.com.ng' : 'automation.airtimetocash.com';
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');
        $url = parse_url($base);
        if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== $expectedHost
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! empty($url['path']) || (isset($url['port']) && $url['port'] !== 443)) {
            throw new \DomainException('Conversion endpoint configuration is invalid.');
        }
        if (app()->environment('testing')) {
            // Testing always refuses un-faked traffic, even if a developer forgot the test guard.
            Http::preventStrayRequests();
        } elseif (! config('airtime_to_cash.live_calls_enabled')) {
            throw new \DomainException('Live conversion calls are disabled.');
        }
        if ($protected && empty($config['token'])) {
            throw new \DomainException('Conversion credentials are not configured.');
        }
        try {
            $http = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(25)->withoutRedirecting();
            if ($protected) {
                $http = $http->withToken($config['token']);
            }
            // No retries, logging, throw(), queueing, or raw response persistence.
            $response = $http->post($base.$path, $payload);

            return [$response->status(), is_array($response->json()) ? $response->json() : []];
        } catch (\Throwable) {
            return [0, []]; // Transport failure may have occurred after transfer delivery.
        } finally {
            unset($payload, $http, $config);
        }
    }
}
