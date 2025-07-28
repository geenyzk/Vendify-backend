<?php

namespace App\Class\Payment\Provider;

use App\Class\Payment\PaymentBase;
use App\Models\Bank;
use App\Models\General;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Monnify extends PaymentBase
{


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

    protected function callback(Request $request): array
    {
        $payload = $request->all();

        if($payload['eventType'] !== "SUCCESSFUL_TRANSACTION") return [];
        $data = $payload['eventData'];
        $customer = $data['customer'] ?? [];
        $source = $data['paymentSourceInformation'][0] ?? [];
        $creditedAmount = $this->creditedAmount($data['amountPaid']);
        $user = User::where('email', $customer['email'] ?? '')->first();
        $user->wallet_balance += $creditedAmount;
        $user->save();

        return [
            "user_id" => $user->id,
            'provider' => $this->providerName,
            'transaction_reference' => $data['transactionReference'],
            'payment_reference' => $data['paymentReference'],
            'response_message' => $data['paymentStatus'],
            'completed_at' => $data['paidOn'] ?? now(),
            'funding_method' => $data['paymentMethod'] ?? 'bank_transfer',
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
