<?php

namespace App\Services\AirtimeToCash\Providers;

use App\Services\AirtimeToCash\AirtimeToCashProviderInterface;
use App\Services\AirtimeToCash\LooksUpTransactions;
use App\Services\AirtimeToCash\ProviderHealthResult;
use App\Services\AirtimeToCash\ProviderCallTrace;
use App\Services\AirtimeToCash\ProviderRequestNotSent;
use App\Services\AirtimeToCash\ProviderResult;
use App\Services\AirtimeToCash\ProviderTransport;

final class TwoFastProvider implements AirtimeToCashProviderInterface, LooksUpTransactions
{
    public function __construct(private ProviderTransport $transport) {}

    public function key(): string
    {
        return '2fast';
    }

    public function networks(): array
    {
        return ['mtn' => ['code' => 1, 'min' => null, 'max' => 10000], 'airtel' => ['code' => 2, 'min' => null, 'max' => 20000]];
    }

    public function capabilities(): array
    {
        return ['quota' => false, 'session' => false, 'lookup' => true];
    }

    public function credentialRequirements(bool $configured = false): array
    {
        return [['key' => 'token', 'label' => 'API key', 'secret' => true, 'configured' => $configured]];
    }

    public function healthCheck(): ProviderHealthResult
    {
        try {
            // Documented, authenticated, read-only catalogue lookup. It performs no
            // conversion, so it proves the credential without moving airtime or money.
            [$http, $body, $request] = $this->transport->post(
                $this->key(),
                '/api/data-plans',
                ['network' => $this->code('mtn'), 'limit' => 1],
                true,
                true,
            );
        } catch (ProviderRequestNotSent $e) {
            app(ProviderCallTrace::class)->record('health', null, null,
                new ProviderResult('not_sent', reason: 'transport_preflight_rejected'), false, $this->key());
            throw $e;
        }
        [$connected, $message, $class] = $this->classifyHealth($http, $body);
        app(ProviderCallTrace::class)->record('health', $http ?: null, null,
            new ProviderResult($connected ? 'success' : 'failed', reason: $class), true, $this->key(),
            [...$request, 'provider_error_class' => $class]);

        return new ProviderHealthResult($connected, $message);
    }

    /**
     * Every documented 2FAST failure mode gets its own verdict. Only 401 may be
     * reported to an admin as a credential problem: 403 is IP whitelist/KYC, 404 is
     * our own base URL, 5xx and transport failures are not the admin's key at all.
     *
     * @return array{0: bool, 1: string, 2: string}
     */
    private function classifyHealth(int $http, #[\SensitiveParameter] array $body): array
    {
        if ($http === 0) {
            return [false, 'Could not reach 2FAST. The request failed before any response (network, DNS or timeout).', 'network_error'];
        }
        if ($http === 401) {
            return [false, 'Authentication rejected by 2FAST. Check the configured API key.', 'auth_rejected'];
        }
        if ($http === 403) {
            return [false, 'Access denied by 2FAST. Check KYC status and API IP whitelist configuration.', 'forbidden_ip_or_kyc'];
        }
        if ($http === 404) {
            return [false, '2FAST did not recognise the connection-test endpoint. Check the configured base URL.', 'endpoint_not_found'];
        }
        if ($http === 429) {
            return [false, 'Provider rate limit reached. Try the connection test later.', 'rate_limited'];
        }
        if ($http >= 500) {
            return [false, '2FAST is temporarily unavailable. This does not indicate a credential problem.', 'provider_unavailable'];
        }
        if ($http >= 400) {
            // Authentication passed; 2FAST rejected the request itself (400 validation, 422, ...).
            return [false, "2FAST authenticated the request but rejected it (HTTP {$http}). This is not a credential problem.", 'request_rejected'];
        }
        if ($http < 200 || $http >= 300) {
            return [false, "2FAST returned an unexpected HTTP {$http} response. Check the configured base URL.", 'unexpected_status'];
        }
        if (($body['status'] ?? null) === 'success') {
            return [true, 'Authentication successful.', 'authenticated'];
        }

        return [false, '2FAST authenticated the request but returned an unrecognised response. This is not a credential problem.', 'authenticated_error_body'];
    }

    private function code(string $network): int
    {
        return $this->networks()[$network]['code'] ?? throw new \DomainException('Unsupported conversion network.');
    }

    private function call(string $operation, #[\SensitiveParameter] array $payload): ProviderResult
    {
        try {
            [$http, $body] = $this->transport->post($this->key(), '/api/Airtime-To-Cash', $payload);
        } catch (ProviderRequestNotSent) {
            $result = new ProviderResult('not_sent', reason: 'transport_preflight_rejected');
            app(ProviderCallTrace::class)->record($operation, null, null, $result, false, $this->key());
            return $result;
        }
        $result = $this->normalize($operation, $http, $body);
        app(ProviderCallTrace::class)->record($operation, $http ?: null, null, $result, true, $this->key());
        return $result;
    }

    public function normalize(string $operation, int $http, #[\SensitiveParameter] array $body): ProviderResult
    {
        if ($http === 0 || $http >= 500 || $http === 409) {
            return new ProviderResult('unknown');
        }
        if ($http === 429) {
            return new ProviderResult('rate_limited');
        }
        if (in_array($http, [401, 403], true)) {
            return new ProviderResult('auth_error');
        }
        if ($http < 200 || $http >= 300) {
            return new ProviderResult('failed');
        }
        if (($body['status'] ?? '') === 'error') {
            return new ProviderResult('failed');
        }
        if (($body['status'] ?? '') !== 'success') {
            return new ProviderResult('unknown');
        }
        $identifier = is_string($body['identifier'] ?? null) && strlen($body['identifier']) <= 2048 ? $body['identifier'] : null;
        $state = 'success';
        $cost = null;
        if ($operation === 'convert') {
            // A success envelope alone also describes "awaiting confirmation".
            // Match only the published completion wording, never generic success.
            $message = is_string($body['message'] ?? null) ? trim($body['message']) : '';
            $state = preg_match('/^Airtime received\. Your wallet has been credited with NGN [0-9,]+(?:\.[0-9]{1,2})?\.$/D', $message)
                ? 'success' : (str_starts_with($message, 'Awaiting airtime confirmation.') ? 'pending' : 'unknown');
            if ($state === 'success' && preg_match('/NGN ([0-9,]+(?:\.[0-9]{1,2})?)\.$/D', $message, $matches)) {
                $cost = ProviderResult::number($matches[1]);
            }
        }
        $skip = ($body['skip_otp'] ?? false) === true;
        if (($operation === 'verify' || $skip) && ! $identifier) {
            $state = 'unknown';
        }

        return new ProviderResult($state, $identifier, ProviderResult::number($body['airtime_balance'] ?? null), skipOtp: $skip, cost: $cost);
    }

    public function requestOtp(string $network, string $phone): ProviderResult
    {
        return $this->call('otp', ['step' => 1, 'network' => $this->code($network), 'phone_number' => $phone]);
    }

    public function verifyOtp(string $network, string $phone, #[\SensitiveParameter] string $otp): ProviderResult
    {
        return $this->call('verify', ['step' => 2, 'network' => $this->code($network), 'phone_number' => $phone, 'otp' => $otp]);
    }

    public function convert(string $network, string $phone, float $amount, string $reference,
        #[\SensitiveParameter] string $identifier, #[\SensitiveParameter] string $pin): ProviderResult
    {
        return $this->call('convert', ['step' => 3, 'network' => $this->code($network), 'identifier' => $identifier,
            'amount' => $amount, 'pin' => $pin, 'reference' => $reference]);
    }

    public function lookup(string $reference): ProviderResult
    {
        [$http, $body] = $this->transport->post($this->key(), '/api/transaction-history', ['reference' => $reference]);
        if ($http !== 200 || ($body['status'] ?? '') !== 'success' || ($body['source'] ?? '') !== 'wallet_history') {
            return new ProviderResult('unknown');
        }
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $type = strtolower(preg_replace('/[^a-z]/i', '', (string) ($data['type'] ?? '')));
        // The published history example is a data purchase, not A2C. Require
        // matching reference AND service before accepting any successful row.
        if (($data['reference'] ?? '') !== $reference || $type !== 'airtimetocash') {
            return new ProviderResult('unknown');
        }

        return new ProviderResult(match ($data['status'] ?? '') {
            'Successful' => 'success', 'Failed' => 'failed', default => 'pending'
        });
    }
}
