<?php

namespace App\Services\AirtimeToCash;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

final class ProviderTransport
{
    private const AUTH_HEADERS = ['authorization', 'proxy-authorization', 'x-api-key', 'api-key', 'apikey', 'x-auth-token', 'token', 'cookie'];

    public function __construct(private AirtimeToCashProviderConfiguration $configuration) {}

    /**
     * Returns [status, body, request]. `request` describes what actually went on
     * the wire without any secret: host, path, header NAMES, the Authorization
     * scheme word, whether a credential followed it, and where the credential came from.
     */
    public function post(string $provider, string $path, #[\SensitiveParameter] array $payload, bool $protected = true, bool $healthCheck = false): array
    {
        $config = $this->configuration->resolved($provider);
        $expectedHost = $config['allowed_host'];
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');
        $url = parse_url($base);
        if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== $expectedHost
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! empty($url['path']) || (isset($url['port']) && $url['port'] !== 443)) {
            throw new ProviderRequestNotSent('Conversion endpoint configuration is invalid.');
        }
        if (app()->environment('testing')) {
            // Testing always refuses un-faked traffic, even if a developer forgot the test guard.
            Http::preventStrayRequests();
        } elseif (! $healthCheck && ! $this->configuration->liveCallsEnabled()) {
            throw new ProviderRequestNotSent('Live conversion calls are disabled.');
        }
        // A pasted credential often carries a trailing newline or space. Trim before use:
        // untrimmed, it malforms the Authorization header and reads as a rejected key.
        $token = trim((string) ($config['token'] ?? ''));
        if ($protected && $token === '') {
            throw new ProviderRequestNotSent('Conversion credentials are not configured.');
        }
        $request = ['request_host' => $expectedHost, 'request_path' => $path, 'credential_source' => $config['token_source'] ?? null];
        try {
            $http = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(25)->withoutRedirecting();
            if ($protected) {
                $http = $http->withToken($token);
            }
            $http->beforeSending(function (ClientRequest $sent) use (&$request) {
                $request = [...$request, ...self::describeHeaders($sent->headers()), 'request_body_types' => self::bodyTypes($sent->body())];
            });
            // No retries, throw(), queueing, or raw response persistence.
            $response = $http->post($base.$path, $payload);

            return [$response->status(), is_array($response->json()) ? $response->json() : [], $request];
        } catch (\Throwable) {
            return [0, [], $request]; // Transport failure may have occurred after transfer delivery.
        } finally {
            unset($payload, $http, $config, $token);
        }
    }

    /** JSON type of each top-level field as serialized on the wire (e.g. amount: int), never a value. */
    private static function bodyTypes(#[\SensitiveParameter] string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? array_map(fn ($value) => get_debug_type($value), $decoded) : null;
    }

    /**
     * One-way, non-reversible 12-hex-character tag of a credential. Safe to log,
     * print and compare; it cannot be reversed into the key. Trims first so the
     * tag matches whatever is actually sent on the wire.
     */
    public static function fingerprint(#[\SensitiveParameter] string $credential): string
    {
        $credential = trim($credential);

        return $credential === '' ? '' : substr(hash('sha256', $credential), 0, 12);
    }

    /** Header names and the Authorization scheme word only; never a header value. */
    private static function describeHeaders(#[\SensitiveParameter] array $headers): array
    {
        $names = array_values(array_unique(array_map('strtolower', array_keys($headers))));
        sort($names);
        [$scheme, $credential, $length, $fingerprint] = [null, null, null, null];
        foreach ($headers as $name => $values) {
            if (strtolower((string) $name) === 'authorization') {
                $parts = explode(' ', trim((string) (is_array($values) ? ($values[0] ?? '') : $values)), 2);
                $scheme = in_array(strtolower($parts[0]), ['bearer', 'basic', 'token'], true) ? strtolower($parts[0]) : 'other';
                $credential = trim($parts[1] ?? '') !== '';
                $length = strlen(trim($parts[1] ?? ''));
                $fingerprint = self::fingerprint($parts[1] ?? '');
            }
        }

        return ['request_header_names' => $names, 'auth_header_names' => array_values(array_intersect($names, self::AUTH_HEADERS)),
            'auth_scheme' => $scheme, 'auth_credential_present' => $credential,
            // Length and a one-way tag, never the credential: enough to prove which key
            // went on the wire and to spot a truncated paste, reversible into nothing.
            'auth_credential_length' => $length, 'auth_credential_fingerprint' => $fingerprint];
    }
}
