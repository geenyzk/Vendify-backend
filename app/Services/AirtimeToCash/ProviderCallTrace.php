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

    public function currentReference(): ?string
    {
        return $this->reference;
    }

    /** @param array $request Secret-free description of the sent request (transport headers, adapter field shapes). */
    public function record(string $operation, ?int $http, mixed $code, ProviderResult $result, bool $attempted, string $provider = 'airtime_to_cash_automation', array $request = []): void
    {
        try {
            Log::info('airtime_to_cash.provider_call', [
                'provider' => $provider,
                'operation' => $operation,
                'internal_reference' => $this->reference,
                'http_status' => $http,
                'provider_code' => is_scalar($code) && in_array((string) $code, ['2000', '3000', '4000', '4030', '4010', '4290', '5030'], true) ? (string) $code : null,
                // Allowlisted semantic message, never arbitrary provider text (which can echo secrets).
                'sanitized_message' => $result->reason ?? $result->state,
                // Quota 5030 classification, e.g. recipients_available or unrecognised_5030;
                // message_terms holds only words from the adapter's fixed vocabulary.
                'semantic_outcome' => $result->semantic,
                'message_field' => $result->messageField,
                'message_terms' => $result->messageTerms,
                'message_pattern' => $result->messageShape['pattern'] ?? null,
                'message_word_count' => $result->messageShape['word_count'] ?? null,
                'message_masked_word_count' => $result->messageShape['masked_word_count'] ?? null,
                // Names only: which auth headers went out and with which scheme, never values.
                'request_host' => $request['request_host'] ?? null,
                'request_path' => $request['request_path'] ?? null,
                'request_header_names' => $request['request_header_names'] ?? null,
                'auth_header_names' => $request['auth_header_names'] ?? null,
                'auth_scheme' => $request['auth_scheme'] ?? null,
                'auth_credential_present' => $request['auth_credential_present'] ?? null,
                'auth_credential_length' => $request['auth_credential_length'] ?? null,
                'credential_source' => $request['credential_source'] ?? null,
                // Fixed adapter vocabulary (auth_rejected, forbidden_ip_or_kyc, ...), never provider prose.
                'provider_error_class' => $request['provider_error_class'] ?? null,
                // Per-field presence/type/length/format of the body sent, and of the session ID returned.
                'payload_fields' => $request['payload_fields'] ?? null,
                'missing_required_fields' => $request['missing_required_fields'] ?? null,
                'response_session_id' => $request['response_session_id'] ?? null,
                'request_body_types' => $request['request_body_types'] ?? null,
                'response_airtime_balance' => $request['response_airtime_balance'] ?? null,
                'response_line_type' => $request['response_line_type'] ?? null,
                'session_login_before_transfer' => $request['session_login_before_transfer'] ?? null,
                'session_login_before_transfer_source' => $request['session_login_before_transfer_source'] ?? null,
                'succeeded' => $result->state === 'success',
                'dispatch_attempted' => $attempted,
                'transfer_submitted' => $operation === 'convert' && $attempted,
                'delivery_confirmed' => $operation === 'convert' && $result->state === 'success',
                'provider_transaction_id' => null, // No such field in the documented Automation response.
            ]);
        } catch (\Throwable) {
            // Logging failure must never change a financial outcome or cause a retry.
        }
    }
}
