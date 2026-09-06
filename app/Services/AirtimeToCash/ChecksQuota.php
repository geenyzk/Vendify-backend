<?php

namespace App\Services\AirtimeToCash;

interface ChecksQuota
{
    public function checkQuota(string $network, float $amount): ProviderResult;
}
