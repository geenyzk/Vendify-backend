<?php

namespace App\Services\AirtimeToCash\Providers;

use App\Services\AirtimeToCash\AirtimeToCashProviderInterface;
use App\Services\AirtimeToCash\ChecksQuota;
use App\Services\AirtimeToCash\ChecksSession;
use App\Services\AirtimeToCash\ProviderHealthResult;
use App\Services\AirtimeToCash\ProviderResult;
use App\Services\AirtimeToCash\ProviderTransport;

final class AutomationProvider implements AirtimeToCashProviderInterface, ChecksQuota, ChecksSession
{
    public function __construct(private ProviderTransport $transport) {}

    public function key(): string
    {
        return 'airtime_to_cash_automation';
    }

    public function networks(): array
    {
        return ['mtn' => ['code' => 'MTN', 'min' => 50, 'max' => 10000],
            'airtel' => ['code' => 'AIRTEL', 'min' => 50, 'max' => 20000],
            'glo' => ['code' => 'GLO', 'min' => 50, 'max' => 1000],
            '9mobile' => ['code' => '9MOBILE', 'min' => 50, 'max' => 20000]];
    }

    public function capabilities(): array
    {
        return ['quota' => true, 'session' => true, 'lookup' => false];
    }

    public function credentialRequirements(bool $configured = false): array
    {
        return [['key' => 'token', 'label' => 'API token', 'secret' => true, 'configured' => $configured]];
    }

    public function healthCheck(): ProviderHealthResult
    {
        [$http, $body] = $this->transport->post(
            $this->key(),
            '/api/v1/check/quota/availability',
            ['networkName' => 'MTN', 'amount' => 50],
            true,
            true,
        );
        $code = (string) ($body['code'] ?? '');
        if (in_array($http, [401, 403], true) || $code === '4030') {
            return new ProviderHealthResult(false, 'Authentication rejected by provider. Check the configured token.');
        }
        if ($http === 429 || $code === '4290') {
            return new ProviderHealthResult(false, 'Provider rate limit reached. Try the connection test later.');
        }
        if ($http === 200 && in_array($code, ['2000', '3000', '4000', '4010', '5030'], true)) {
            return new ProviderHealthResult(true, 'Authentication successful.');
        }

        return new ProviderHealthResult(false, 'Provider did not return a recognised health response.');
    }

    private function code(string $network): string
    {
        return $this->networks()[$network]['code'] ?? throw new \DomainException('Unsupported conversion network.');
    }

    private function call(string $operation, string $path, #[\SensitiveParameter] array $payload, bool $protected = true): ProviderResult
    {
        [$status, $body] = $this->transport->post($this->key(), '/api/v1/'.$path, $payload, $protected);

        return $this->normalize($operation, $status, $body);
    }

    public function normalize(string $operation, int $http, #[\SensitiveParameter] array $body): ProviderResult
    {
        if ($http === 0 || $http >= 500) {
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
        $code = (string) ($body['code'] ?? '');
        // The published quota examples both use 5030. Fail closed; only a
        // documented general-success code qualifies until the provider clarifies.
        $state = match ($code) {
            '2000' => 'success', '3000' => 'failed', '4000' => 'pending',
            '4030' => 'auth_error', '4010' => 'session_expired', '4290' => 'rate_limited',
            '5030' => 'unavailable', default => 'unknown',
        };
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $identifier = is_string($data['sessionId'] ?? null) && strlen($data['sessionId']) <= 2048 ? $data['sessionId'] : null;
        $amount = ProviderResult::number($data['amountConverted'] ?? null);
        if ($state === 'success' && in_array($operation, ['verify', 'session'], true) && ! $identifier) {
            $state = 'unknown';
        }
        if ($state === 'success' && $operation === 'convert' && ($amount === null || $amount <= 0)) {
            $state = 'unknown';
        }

        return new ProviderResult($state, $identifier, ProviderResult::number($data['airtimeBalance'] ?? null),
            ProviderResult::number($data['automationCharges'] ?? null), convertedAmount: $amount);
    }

    public function requestOtp(string $network, string $phone): ProviderResult
    {
        return $this->call('otp', 'generate/otp', ['networkName' => $this->code($network), 'sender' => $phone], false);
    }

    public function verifyOtp(string $network, string $phone, #[\SensitiveParameter] string $otp): ProviderResult
    {
        return $this->call('verify', 'verify/otp', ['networkName' => $this->code($network), 'sender' => $phone, 'otp' => $otp], false);
    }

    public function checkSession(string $network, string $phone, #[\SensitiveParameter] string $identifier): ProviderResult
    {
        return $this->call('session', 'login/with/session/id', ['networkName' => $this->code($network), 'sender' => $phone, 'sessionId' => $identifier]);
    }

    public function checkQuota(string $network, float $amount): ProviderResult
    {
        return $this->call('quota', 'check/quota/availability', ['networkName' => $this->code($network), 'amount' => $amount]);
    }

    public function convert(string $network, string $phone, float $amount, string $reference,
        #[\SensitiveParameter] string $identifier, #[\SensitiveParameter] string $pin): ProviderResult
    {
        return $this->call('convert', 'transfer/airtime', ['networkName' => $this->code($network), 'sender' => $phone,
            'amount' => $amount, 'reference' => $reference, 'sessionId' => $identifier, 'pin' => $pin]);
    }
}
