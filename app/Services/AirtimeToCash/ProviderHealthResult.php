<?php

namespace App\Services\AirtimeToCash;

final readonly class ProviderHealthResult
{
    public function __construct(public bool $connected, public string $message) {}

    public function status(): string
    {
        return $this->connected ? 'connected' : 'failed';
    }

    public function __debugInfo(): array
    {
        return ['connected' => $this->connected, 'message' => $this->message];
    }
}
