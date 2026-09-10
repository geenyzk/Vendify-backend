<?php

namespace App\Services\AirtimeToCash;

use Illuminate\Support\Facades\Log;

/** Request-scoped correlation. Never retain payloads, credentials or provider prose. */
final class ProviderCallTrace
{
    private ?string $reference = null;

    public function within(string $reference, callable $operation): mixed
    {
        $previous = $this->reference;
        $this->reference = $reference;
        try {
            return $operation();
        } finally {
            $this->reference = $previous;
        }
    }

    public function record(string $operation, ?int $http, mixed $code, ProviderResult $result, bool $attempted): void
    {
        try {
            Log::info('airtime_to_cash.provider_call', [
                'provider' => 'airtime_to_cash_automation',
                'operation' => $operation,
                'internal_reference' => $this->reference,
                'http_status' => $http,
                'provider_code' => is_scalar($code) && in_array((string) $code, ['2000', '3000', '4000', '4030', '4010', '4290', '5030'], true) ? (string) $code : null,
                // Allowlisted semantic message, never arbitrary provider text (which can echo secrets).
                'sanitized_message' => $result->reason ?? $result->state,
                'succeeded' => $result->state === 'success',
                'dispatch_attempted' => $attempted,
                'delivery_confirmed' => $operation === 'convert' && $result->state === 'success',
                'provider_transaction_id' => null, // No such field in the documented Automation response.
            ]);
        } catch (\Throwable) {
            // Logging failure must never change a financial outcome or cause a retry.
        }
    }
}
