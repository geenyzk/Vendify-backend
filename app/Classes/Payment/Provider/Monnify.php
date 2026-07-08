<?php

namespace App\Classes\Payment\Provider;

use App\Classes\Payment\PaymentBase;
use App\Models\Bank;
use App\Models\General;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Monnify extends PaymentBase
{

    // Was never declared — creditedAmount()'s Provider::whereName($this->providerName)
    // lookup silently matched nothing (uninitialized typed property access is a
    // \TypeError, not \Exception, so it used to fatal-crash the whole webhook).
    protected string $providerName = 'monnify';

    function connect(): mixed
    {
        return "";
    }

    function checkBalance(): string
    {
        return "";
    }



    public function generate($payload):array|null
    {
        try {
            $payloadResponse = $this->formatPayload($payload);
            $response = Http::withHeaders($this->getHeaders())
                ->post($this->provider->base_url . "/virtual-account-numbers", $payloadResponse);

            Log::info("Generating virtual account for {$payload->email}...", [
                'response' => $response->json()
            ]);

            if ($response->successful()) {
                $data = $response->json('responseBody');
                return $this->formatResponse(array_merge($data, $payloadResponse), $payload);
            } else {
                Log::error("Failed to generate account.", [
                    'error' => $response->body()
                ]);
                return null;
            }
        } catch (\Throwable $th) {
            Log::error($th);

            return null;
        }

    }


    protected function getHeaders(): array
    {
        $apiKey = $this->provider->api_key;
        // providers has no secret_key column (confirmed via SHOW COLUMNS) —
        // this always read null, so every real Monnify API call's Basic auth
        // was broken independent of the webhook bugs below. `password` is
        // this table's existing convention for a provider's secret credential.
        $secretKey = $this->provider->password;
        $authString = base64_encode("{$apiKey}:{$secretKey}");

        return [
            "Authorization" => "Basic {$authString}",
            "Content-Type" => "application/json",
        ];
    }


    function formatPayload(array|User $payload, ?User $user = null): array
    {
        $sessionUser = $user ?? $payload;
        $txRef = Transaction::generateTransactionId();
        $nameParts = explode(' ', $sessionUser->fullname);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';
        $fullName = "{$firstName} {$lastName}";
        $gen = General::first();

        return [
            "walletReference" => $txRef,
            "walletName" => "Wallet - {$txRef}",
            "customerName" => $fullName,
            "bvnDetails" => [
                "bvn" => $sessionUser->bvn ?? $gen->bvn,
                // "bvnDateOfBirth" => $sessionUser->dob ?? '1990-01-01', // Make sure this exists
            ],
            "customerEmail" => $sessionUser->email,
        ];
    }


    function formatResponse(array $data, ?User $user = null): array
    {
        return [
            'user_id' => $user->id,
            'account_type' => 'virtual',
            'bank_account' => $data['accountNumber'], // From responseBody
            'bank_name' => 'Monnify', // Or a specific name if provided
            'provider' => 'monnify',
            'status' => 'active',
            'amount' => 0.00, // Default since not provided
            'ref' => $data['walletReference'],
            'tx_ref' => $data['walletReference'],
            'expired_at' => now()->addYears(1),
        ];
    }

    // Parsing only — see PaymentBase::callback() docblock. Wallet crediting
    // and idempotency are centralized in PaymentBase::webhook().
    protected function callback(Request $request): array
    {
        $payload = $request->all();

        if (($payload['eventType'] ?? null) !== "SUCCESSFUL_TRANSACTION") {
            return [];
        }

        $data = $payload['eventData'] ?? [];
        $customer = $data['customer'] ?? [];
        $source = $data['paymentSourceInformation'][0] ?? [];
        $creditedAmount = $this->creditedAmount($data['amountPaid'] ?? 0);
        $status = strtolower($data['paymentStatus'] ?? '') === 'paid' ? 'success' : 'fail';

        return [
            'user_email' => $customer['email'] ?? null,
            'provider' => $this->providerName,
            'transaction_reference' => $data['transactionReference'] ?? null,
            'payment_reference' => $data['paymentReference'] ?? null,
            'response_message' => $data['paymentStatus'] ?? null,
            'completed_at' => $data['paidOn'] ?? now(),
            'funding_method' => $data['paymentMethod'] ?? 'bank_transfer',
            'service_fee' => (float) ($data['totalPayable'] ?? 0) - (float) ($data['settlementAmount'] ?? 0),
            'platform' => 'web',
            'transaction_type' => 'wallet_funding',
            'account_or_phone' => $source['accountNumber'] ?? null,
            'amount' => $creditedAmount,
            'status' => $status,
            'receiver' => $data['destinationAccountInformation']['accountNumber'] ?? null,
        ];
    }

    // Monnify signs the raw request body with HMAC-SHA512 using your Client
    // Secret Key (the same secret used for Basic auth in getHeaders() above —
    // Monnify has no separate "webhook secret" dashboard field) and sends the
    // hex digest in the `monnify-signature` header.
    protected function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->provider->password;
        if (empty($secret)) {
            Log::warning('Monnify webhook secret (password) not configured — rejecting webhook.', [
                'provider_id' => $this->provider->id,
            ]);
            return false;
        }

        $received = $request->header('monnify-signature');
        if (empty($received)) {
            return false;
        }

        $expected = hash_hmac('sha512', $request->getContent(), $secret);
        return hash_equals($expected, $received);
    }

}
