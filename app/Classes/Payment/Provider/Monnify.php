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
    // Without this, creditedAmount()'s Provider::whereName($this->providerName)
    // lookup reads an uninitialized typed property and throws, which — since
    // creditedAmount() runs before the wallet_balance credit in callback() —
    // meant a real successful Monnify payment would silently never be
    // credited (the exception is swallowed by PaymentBase::webhook()'s catch).
    protected string $providerName = 'monnify';

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

<<<<<<<< HEAD:app/Classes/Payment/Provider/Monnify.php
    // Monnify does not expose a transfer/payout API in the current integration.
    // Override this when Monnify payout support is added.

    /**
     * Fetch the list of banks supported by Monnify for transfers.
     */
    public function getBanks(): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->get($this->provider->base_url . '/banks');

            if ($response->successful()) {
                $banks = $response->json('responseBody') ?? [];
                return collect($banks)->map(fn($bank) => [
                    'code' => $bank['code'],
                    'name' => $bank['name'],
                ])->values()->all();
            }

            Log::error('Monnify: failed to fetch banks', ['error' => $response->body()]);
            return [];
        } catch (\Throwable $e) {
            Log::error('Monnify: getBanks exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

========
    // Parsing only — see PaymentBase::callback() docblock. Wallet crediting
    // and idempotency are centralized in PaymentBase::webhook().
>>>>>>>> d00a16b3fbdfa6668d2bb5d0af13afd0eb17f353:app/Class/Payment/Provider/Monnify.php
    protected function callback(Request $request): array
    {
        $payload = $request->all();

        if (($payload['eventType'] ?? null) !== "SUCCESSFUL_TRANSACTION") {
            return [];
        }

        $data = $payload['eventData'] ?? [];
        $customer = $data['customer'] ?? [];
        $source = $data['paymentSourceInformation'][0] ?? [];
<<<<<<<< HEAD:app/Classes/Payment/Provider/Monnify.php
        $user = User::where('email', $customer['email'] ?? '')->first();
        if (!$user) {
            Log::warning('Monnify webhook: no user found for email', ['email' => $customer['email'] ?? null]);
            return [];
        }

        $creditedAmount = $this->creditedAmount($data['amountPaid']);

        // Gateways retry webhooks (missed 200, network hiccup, etc.) — only
        // credit the wallet the first time this transaction_reference is
        // seen as successful, otherwise a retry double-credits the user.
        $alreadyCredited = Transaction::where('transaction_reference', $data['transactionReference'])
            ->where('status', 'success')
            ->exists();
        if (!$alreadyCredited) {
            $user->wallet_balance += $creditedAmount;
            $user->save();
        }
========
        $creditedAmount = $this->creditedAmount($data['amountPaid'] ?? 0);
        $status = strtolower($data['paymentStatus'] ?? '') === 'paid' ? 'success' : 'fail';
>>>>>>>> d00a16b3fbdfa6668d2bb5d0af13afd0eb17f353:app/Class/Payment/Provider/Monnify.php

        return [
            'user_email' => $customer['email'] ?? null,
            'provider' => $this->providerName,
            'transaction_reference' => $data['transactionReference'] ?? null,
            'payment_reference' => $data['paymentReference'] ?? null,
            'response_message' => $data['paymentStatus'] ?? null,
            'completed_at' => $data['paidOn'] ?? now(),
<<<<<<<< HEAD:app/Classes/Payment/Provider/Monnify.php
            // `funding_method` is a fixed DB enum (bank_transfer/credit_card/
            // manual/other) — Monnify's own paymentMethod string (e.g.
            // "ACCOUNT_TRANSFER") isn't one of those and would fail the
            // insert, so this is always a bank transfer, same as
            // FlutterWave/PaymentPoint's callbacks.
            'funding_method' => 'bank_transfer',
            'service_fee' => (float) $data['totalPayable'] - (float) $data['settlementAmount'],
========
            'funding_method' => $data['paymentMethod'] ?? 'bank_transfer',
            'service_fee' => (float) ($data['totalPayable'] ?? 0) - (float) ($data['settlementAmount'] ?? 0),
>>>>>>>> d00a16b3fbdfa6668d2bb5d0af13afd0eb17f353:app/Class/Payment/Provider/Monnify.php
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
