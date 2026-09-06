<?php

namespace App\Services\AirtimeToCash;

interface AirtimeToCashProviderInterface
{
    public function key(): string;

    /** Canonical Vendify name => provider code/min/max. Null min means undocumented. */
    public function networks(): array;

    public function capabilities(): array;

    public function requestOtp(string $network, string $phone): ProviderResult;

    public function verifyOtp(string $network, string $phone, #[\SensitiveParameter] string $otp): ProviderResult;

    public function convert(string $network, string $phone, float $amount, string $reference,
        #[\SensitiveParameter] string $identifier, #[\SensitiveParameter] string $pin): ProviderResult;
}
