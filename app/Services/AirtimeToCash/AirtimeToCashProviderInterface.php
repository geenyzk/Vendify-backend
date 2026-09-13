<?php

namespace App\Services\AirtimeToCash;

interface AirtimeToCashProviderInterface
{
    public function key(): string;

    /** Canonical Vendify name => provider code/min/max. Null min means undocumented. */
    public function networks(): array;

    public function capabilities(): array;

    /** Safe admin-facing field schema; values are never included. */
    public function credentialRequirements(bool $configured = false): array;

    /** Authentication-only check that cannot initiate an airtime conversion. */
    public function healthCheck(): ProviderHealthResult;

    public function requestOtp(string $network, string $phone): ProviderResult;

    public function verifyOtp(string $network, string $phone, #[\SensitiveParameter] string $otp): ProviderResult;

    public function convert(string $network, string $phone, float $amount, string $reference,
        #[\SensitiveParameter] string $identifier, #[\SensitiveParameter] string $pin): ProviderResult;
}
