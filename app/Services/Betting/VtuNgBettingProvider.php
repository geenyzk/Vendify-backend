<?php

namespace App\Services\Betting;

use App\Contracts\BettingProviderInterface;
use App\Models\BettingProvider;
use App\Models\Vendor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class VtuNgBettingProvider implements BettingProviderInterface
{
    private const BILLERS = [
        '1xBet', 'BangBet', 'Bet9ja', 'BetKing', 'BetLand', 'BetLion', 'BetWay',
        'CloudBet', 'LiveScoreBet', 'MerryBet', 'NaijaBet', 'NairaBet', 'SportyBet', 'SupaBet',
    ];

    public function __construct(private readonly Vendor $vendor) {}

    public function verifyCustomer(BettingProvider $provider, string $customerId): array
    {
        try {
            $response = $this->post('/verify-customer', [
                'customer_id' => $customerId,
                'service_id' => $provider->biller_id,
            ]);
            return $this->normalize($response->json() ?? [], true, $response->status());
        } catch (ConnectionException $e) {
            return $this->transportFailure($e, false);
        }
    }

    public function fundAccount(BettingProvider $provider, string $customerId, float $amount, string $reference): array
    {
        $requestId = substr('vendify_' . $reference, 0, 50);
        try {
            $response = $this->post('/betting', [
                'request_id' => $requestId,
                'customer_id' => $customerId,
                'service_id' => $provider->biller_id,
                'amount' => $amount,
            ]);
            $result = $this->normalize($response->json() ?? [], false, $response->status());
            $result['provider_reference'] ??= $requestId;
            return $result;
        } catch (ConnectionException $e) {
            return $this->transportFailure($e, true, $requestId);
        }
    }

    public function supportedBillers(): array
    {
        // VTU.ng v2 documents these IDs but does not expose a betting catalog.
        return array_map(fn (string $name) => [
            'name' => $name,
            'biller_id' => $name,
            'minimum_amount' => 100,
            'maximum_amount' => 100000,
            'metadata' => ['source' => 'vtu_ng_v2_documentation'],
        ], self::BILLERS);
    }

    private function client(string $token)
    {
        return Http::connectTimeout(config('betting.connect_timeout', 5))
            ->timeout(config('betting.timeout', 20))->acceptJson()->withToken($token);
    }

    private function token(): string
    {
        if ($cached = Cache::get($this->tokenCacheKey())) {
            return (string) $cached;
        }

        $managedToken = $this->vendor->api_key ?: config('services.vtu_ng.api_token');
        if ($managedToken) return (string) $managedToken;

        return $this->refreshToken();
    }

    private function refreshToken(): string
    {
        $username = $this->vendor->username ?: config('services.vtu_ng.username');
        $password = $this->vendor->password ?: config('services.vtu_ng.password');
        if (! $username || ! $password) {
            throw new \RuntimeException('VTU.ng username/password is required to refresh an invalidated API token.');
        }

        $response = Http::connectTimeout(5)->timeout(15)->acceptJson()->post(
            $this->rootUrl() . '/jwt-auth/v1/token',
            ['username' => $username, 'password' => $password],
        );
        $token = $response->json('token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new \RuntimeException('VTU.ng authentication failed.');
        }

        Cache::put($this->tokenCacheKey(), $token, now()->addDays(6));
        return $token;
    }

    private function tokenCacheKey(): string
    {
        $username = $this->vendor->username ?: config('services.vtu_ng.username');
        return 'vtu-ng:betting-token:' . ($this->vendor->id ?: hash('sha256', (string) $username));
    }

    private function post(string $path, array $payload)
    {
        $response = $this->client($this->token())->post($this->url($path), $payload);
        if (! $this->tokenWasInvalidated($response->json() ?? [], $response->status())) {
            return $response;
        }

        // VTU.ng invalidates every older JWT whenever a newer one is issued.
        // Refresh once and replay the request with the new token.
        Cache::forget($this->tokenCacheKey());
        return $this->client($this->refreshToken())->post($this->url($path), $payload);
    }

    private function tokenWasInvalidated(array $body, int $httpStatus): bool
    {
        $code = strtolower((string) ($body['code'] ?? ''));
        $message = (string) ($body['message'] ?? '');

        return $httpStatus === 403
            && (in_array($code, ['jwt_auth_failed', 'jwt_auth_invalid_token'], true)
                || (bool) preg_match('/token has been invalidated|invalid (?:jwt|token)|token (?:is )?expired/i', $message));
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->vendor->base_url ?: config('services.vtu_ng.base_url')), '/');
    }

    private function rootUrl(): string
    {
        return preg_replace('#/api/v2$#', '', $this->baseUrl()) ?: 'https://vtu.ng/wp-json';
    }

    private function url(string $path): string { return $this->baseUrl() . $path; }

    private function normalize(array $body, bool $verification, int $httpStatus): array
    {
        $code = strtolower((string) ($body['code'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $upstreamStatus = strtolower((string) ($data['status'] ?? ''));
        $authenticationFailure = $httpStatus === 403
            || in_array($code, ['rest_forbidden', 'jwt_auth_failed', 'jwt_auth_invalid_token'], true);
        $permissionDenied = (bool) preg_match('/not allowed to vend for this biller|not authorised for this biller|not authorized for this biller/i', $message);
        $unsupported = in_array($code, ['invalid_service_id', 'invalid_service'], true);
        $customerMissing = $verification && in_array($code, ['failure', 'invalid_field'], true);
        $pending = in_array($upstreamStatus, ['processing-api', 'initiated-api', 'queued-api', 'pending', 'on-hold'], true)
            || preg_match('/processing|pending|initiated/i', $message);

        if ($permissionDenied) [$status, $internal] = ['failed', 'provider_permission_denied'];
        elseif ($authenticationFailure) [$status, $internal] = ['failed', 'provider_unavailable'];
        elseif ($unsupported) [$status, $internal] = ['failed', 'unsupported_biller'];
        elseif ($customerMissing) [$status, $internal] = ['failed', 'customer_not_found'];
        elseif ($verification && $code === 'success') [$status, $internal] = ['success', 'success'];
        elseif ($pending) [$status, $internal] = ['pending', 'pending'];
        elseif ($code === 'success' && $upstreamStatus === 'completed-api') [$status, $internal] = ['success', 'success'];
        elseif (in_array($upstreamStatus, ['refunded', 'failed', 'failed-api', 'cancelled', 'cancelled-api'], true)) [$status, $internal] = ['failed', 'failed'];
        elseif (! $verification && $httpStatus >= 500) [$status, $internal] = ['pending', 'provider_unavailable'];
        elseif (in_array($code, ['insufficient_funds', 'wallet_busy', 'wallet_error'], true) || $httpStatus >= 500) [$status, $internal] = ['failed', 'provider_unavailable'];
        else [$status, $internal] = ['failed', $verification ? 'customer_not_found' : 'failed'];

        return [
            'status' => $status,
            'internal_status' => $internal,
            'message' => $message,
            'customer_name' => $data['customer_name'] ?? null,
            'provider_reference' => $data['request_id'] ?? $body['request_id'] ?? null,
            'cost' => isset($data['amount_charged']) ? (float) $data['amount_charged'] : null,
            'raw' => $body,
        ];
    }

    private function transportFailure(ConnectionException $e, bool $ambiguous, ?string $requestId = null): array
    {
        return [
            'status' => $ambiguous ? 'pending' : 'failed',
            'internal_status' => 'provider_unavailable',
            'message' => $ambiguous ? 'The request is still being confirmed.' : 'Provider unavailable.',
            'provider_reference' => $requestId,
            'raw' => ['exception' => class_basename($e)],
        ];
    }
}
