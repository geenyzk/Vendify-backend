<?php

namespace App\Services\AirtimeToCash;

interface LooksUpTransactions
{
    public function lookup(string $reference): ProviderResult;
}
