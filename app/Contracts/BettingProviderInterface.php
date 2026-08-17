<?php

namespace App\Contracts;

use App\Models\BettingProvider;

interface BettingProviderInterface
{
    public function verifyCustomer(BettingProvider $provider, string $customerId): array;

    public function fundAccount(BettingProvider $provider, string $customerId, float $amount, string $reference): array;

    public function supportedBillers(): array;
}
