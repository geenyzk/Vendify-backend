<?php

namespace App\Services\AirtimeToCash;

/** No raw body, request, headers or provider prose may cross the adapter boundary. */
final readonly class ProviderResult
{
    public function __construct(
        public string $state,
        private ?string $identifier = null,
        public ?float $balance = null,
        public ?float $fee = null,
        public bool $skipOtp = false,
        public ?float $convertedAmount = null,
        public ?float $cost = null,
        public ?string $reason = null,
    ) {}

    public function identifier(): ?string
    {
        return $this->identifier;
    }

    public function __debugInfo(): array
    {
        return ['state' => $this->state, 'reason' => $this->reason];
    }

    public function terminal(): bool
    {
        return in_array($this->state, ['success', 'failed'], true);
    }

    public function retryable(string $operation): bool
    {
        // A transfer response never grants permission to repeat a telecom operation.
        return $operation !== 'convert' && in_array($this->state, ['rate_limited', 'unavailable'], true);
    }

    public static function number(mixed $value): ?float
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = preg_replace('/^(₦|NGN|N)\s*/u', '', trim((string) $value));
        $value = str_replace(',', '', $value);

        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
    }
}
