<?php

namespace App\Services\AirtimeToCash;

final class RequestSecrets
{
    public function __construct(#[\SensitiveParameter] private array $values) {}

    public function take(string $key): string
    {
        $value = $this->values[$key] ?? '';
        unset($this->values[$key]);

        return is_string($value) ? $value : '';
    }

    public function clear(): void
    {
        $this->values = [];
    }

    public function __debugInfo(): array
    {
        return ['secrets' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        return [];
    }
}
