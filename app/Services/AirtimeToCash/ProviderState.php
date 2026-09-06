<?php

namespace App\Services\AirtimeToCash;

use App\Models\AirtimeToCashRequest;

final class ProviderState
{
    private const NEXT = [
        'created' => ['awaiting_otp', 'ready_to_transfer', 'manual_review', 'failed'],
        'awaiting_otp' => ['verifying_otp', 'expired'],
        'verifying_otp' => ['awaiting_otp', 'ready_to_transfer', 'expired', 'manual_review'],
        'ready_to_transfer' => ['processing', 'expired'],
        'processing' => ['provider_confirmed', 'provider_pending', 'failed', 'session_expired'],
        'provider_pending' => ['provider_confirmed', 'failed', 'manual_review'],
        'manual_review' => ['provider_confirmed', 'failed'],
        'session_expired' => ['created'],
        'expired' => ['created'],
        'provider_confirmed' => ['settlement_pending', 'completed'],
        'settlement_pending' => ['completed'],
        'completed' => [], 'failed' => [],
    ];

    public static function move(AirtimeToCashRequest $request, string $state): void
    {
        if ($state === $request->provider_status) {
            return;
        }
        if (! in_array($state, self::NEXT[$request->provider_status] ?? [], true)) {
            throw new \DomainException('Invalid conversion state transition.');
        }
        $request->provider_status = $state;
        if (in_array($state, ['failed', 'completed'], true)) {
            $request->active_session_key = null;
            $request->provider_identifier = null;
        }
    }
}
