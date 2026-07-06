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
                ->post($this->provider->base_url . "/bank-transfer/reserved-accounts", $payloadResponse);

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
        $secretKey = $this->provider->secret_key; // Ensure this exists
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
            "accountReference" => $txRef,
            "accountName" => "Wallet - {$txRef}",
            "currencyCode" => "NGN",
            "contractCode" => $this->provider->username,
            "customerName" => $fullName,
            "bvn" => $sessionUser->bvn ?? $gen->bvn,
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

    protected function callback(Request $request): array
    {
        $payload = $request->all();

        if($payload['eventType'] !== "SUCCESSFUL_TRANSACTION") return [];
        $data = $payload['eventData'];
        $customer = $data['customer'] ?? [];
        $source = $data['paymentSourceInformation'][0] ?? [];
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

        return [
            "user_id" => $user->id,
            'provider' => $this->providerName,
            'transaction_reference' => $data['transactionReference'],
            'payment_reference' => $data['paymentReference'],
            'response_message' => $data['paymentStatus'],
            'completed_at' => $data['paidOn'] ?? now(),
            // `funding_method` is a fixed DB enum (bank_transfer/credit_card/
            // manual/other) — Monnify's own paymentMethod string (e.g.
            // "ACCOUNT_TRANSFER") isn't one of those and would fail the
            // insert, so this is always a bank transfer, same as
            // FlutterWave/PaymentPoint's callbacks.
            'funding_method' => 'bank_transfer',
            'service_fee' => (float) $data['totalPayable'] - (float) $data['settlementAmount'],
            'platform' => 'web',
            'transaction_type' => 'wallet_funding',
            'account_or_phone' => $source['accountNumber'] ?? null,
            'amount' => $creditedAmount ?? 0.00,
            'status' => strtolower($data['paymentStatus']) === 'paid' ? 'success' : 'failed',
            'receiver' => $data['destinationAccountInformation']['accountNumber'] ?? null,
        ];
    }


}
