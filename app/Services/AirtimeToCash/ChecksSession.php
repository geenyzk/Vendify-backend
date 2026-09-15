<?php

namespace App\Services\AirtimeToCash;

interface ChecksSession
{
    public function checkSession(string $network, string $phone, #[\SensitiveParameter] string $identifier): ProviderResult;

    /** Whether the session must be (re)confirmed immediately before each transfer. */
    public function loginBeforeTransfer(): bool;
}
