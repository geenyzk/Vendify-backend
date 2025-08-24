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
            "bvn" => $sessionUser->bvn ?? $gen->bvn
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


    protected function callback(HttpRequest $request): array
    {
        $payload = $request->all();
        $data = $payload['data'];
        $customer = $data['customer'];
        $creditedAmount = $this->creditedAmount($data['amount']);
        $user = User::where('email', $customer['email'])->first();
        $user->wallet_balance += $creditedAmount;
        $user->save();
        return [
            "user_id" => $user->id,
            'provider' => $this->providerName,
            'transaction_reference' =>  $data['tx_ref'] ,
            'payment_reference' => $data['flw_ref'] ?? null,
            'response_message' => $data['processor_response'] ?? 'Transaction failed',
            'completed_at' => now(),
            "funding_method" => "bank_transfer",
            'service_fee' => $data['app_fee'] ?? 0.00,
            'platform' => 'web',
            'transaction_type' => 'wallet_funding',
            'account_or_phone' => $customer['phone_number'] ?? null,
            'amount' => $creditedAmount ?? 0.00,
            'status' => $data['status'] =="successful" ?"success" :"fail" ,
            'receiver' => $customer['phone_number'] ?? null,
        ];
    }




}
