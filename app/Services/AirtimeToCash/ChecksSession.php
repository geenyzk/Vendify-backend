<?php

namespace App\Services\AirtimeToCash;

interface ChecksSession
{
    public function checkSession(string $network, string $phone, #[\SensitiveParameter] string $identifier): ProviderResult;
}
