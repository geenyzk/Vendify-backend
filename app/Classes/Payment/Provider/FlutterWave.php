<?php

namespace App\Classes\Payment\Provider;

use App\Classes\Payment\PaymentBase;
use App\Models\General;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlutterWave extends PaymentBase
{

    protected string $providerName = 'flutterwave';


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
                $data = $response->json('data');
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
        return [
            "Authorization" => "Bearer " . $this->provider->api_key
        ];
    }

    function formatPayload(array|User $payload, ?User $user = null): array
    {
            $sessionUser = $user !== null ? $user : $payload ;
            $txRef = Transaction::generateTransactionId();
            $nameParts = explode(' ', $sessionUser->fullname);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
            $gen = General::first();
        return [
            "email" => $sessionUser->email,
            "tx_ref" => $txRef,
            "phonenumber" => $sessionUser->phone,
            "is_permanent" => true,
            "firstname" => $firstName,
            "lastname" => $lastName,
            "bvn" => $sessionUser->bvn ?? $gen?->bvn
        ];
    }

    function formatResponse(array $data, ?User $user = null): array
    {



        return  [
            'user_id' => $user->id,
            'account_type' => 'virtual',
            'bank_account' => $data['account_number'],
            'bank_name' => $data['bank_name'],
            'provider' => $this->providerName,
            'status' => 'active',
            'amount' => $data['amount'] ?? 0.00,
            'ref' => $data['flw_ref'] ?? null,
            'tx_ref' => $data['tx_ref'],
            'expired_at' => now()->addYears(1)
        ];
    }


    // Parsing only — no wallet credit / no $user->save() here. Centralized
    // in PaymentBase::webhook() so it happens exactly once, only after
    // signature verification + idempotency checks pass. This used to
    // credit the wallet unconditionally right here, before the
    // success/fail status below was even evaluated.
    protected function callback(HttpRequest $request): array
    {
        $payload = $request->all();
        $data = $payload['data'] ?? [];
        $customer = $data['customer'] ?? [];
        $status = ($data['status'] ?? null) === 'successful' ? 'success' : 'fail';
        $creditedAmount = $this->creditedAmount($data['amount'] ?? 0);

        return [
            'user_email' => $customer['email'] ?? null,
            'provider' => $this->providerName,
            'transaction_reference' => $data['tx_ref'] ?? null,
            'payment_reference' => $data['flw_ref'] ?? null,
            'response_message' => $data['processor_response'] ?? ($status === 'success' ? 'Transaction successful' : 'Transaction failed'),
            'completed_at' => now(),
            "funding_method" => "bank_transfer",
            'service_fee' => $data['app_fee'] ?? 0.00,
            'platform' => 'web',
            'transaction_type' => 'wallet_funding',
            'account_or_phone' => $customer['phone_number'] ?? null,
            'amount' => $creditedAmount,
            'status' => $status,
            'receiver' => $customer['phone_number'] ?? null,
        ];
    }

    // Flutterwave sends a `verif-hash` header that must match the "Secret
    // Hash" configured in the Flutterwave dashboard's webhook settings —
    // stored per-provider in the (otherwise-unused) webhook_access column.
    protected function verifyWebhookSignature(HttpRequest $request): bool
    {
        $expected = $this->provider->webhook_access;
        if (empty($expected)) {
            Log::warning('FlutterWave webhook secret (webhook_access) not configured — rejecting webhook.', [
                'provider_id' => $this->provider->id,
            ]);
            return false;
        }

        $received = $request->header('verif-hash');
        return $received !== null && hash_equals($expected, $received);
    }




}
