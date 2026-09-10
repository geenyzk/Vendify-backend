<?php

namespace App\Services\AirtimeToCash\Providers;

use App\Services\AirtimeToCash\AirtimeToCashProviderInterface;
use App\Services\AirtimeToCash\LooksUpTransactions;
use App\Services\AirtimeToCash\ProviderHealthResult;
use App\Services\AirtimeToCash\ProviderResult;
use App\Services\AirtimeToCash\ProviderTransport;
use Illuminate\Support\Str;

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
        [$http, $body] = $this->transport->post(
            $this->key(),
            '/api/transaction-history',
            ['reference' => 'ATC-HEALTH-'.Str::uuid()],
            true,
            true,
        );
        if (in_array($http, [401, 403], true)) {
            return new ProviderHealthResult(false, 'Authentication rejected by provider. Check the configured API key.');
        }
        if ($http === 429) {
            return new ProviderHealthResult(false, 'Provider rate limit reached. Try the connection test later.');
        }
        $message = is_string($body['message'] ?? null) ? $body['message'] : '';
        if ($http === 200 && ($body['status'] ?? null) === 'error' && str_starts_with($message, 'Transaction not found')) {
            return new ProviderHealthResult(true, 'Authentication successful.');
        }

        return new ProviderHealthResult(false, 'Provider did not return a recognised health response.');
    }

    private function code(string $network): int
    {
        return $this->networks()[$network]['code'] ?? throw new \DomainException('Unsupported conversion network.');
    }

    private function call(string $operation, #[\SensitiveParameter] array $payload): ProviderResult
    {
        [$http, $body] = $this->transport->post($this->key(), '/api/Airtime-To-Cash', $payload);

        return $this->normalize($operation, $http, $body);
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
