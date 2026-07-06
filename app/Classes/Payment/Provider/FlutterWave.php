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


    /**
     * Push funds to a vendor's bank account via Flutterwave Transfers API.
     */
    public function transfer(array $payload): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->post($this->provider->base_url . '/transfers', [
                    'account_bank'    => $payload['account_bank'],
                    'account_number'  => $payload['account_number'],
                    'amount'          => $payload['amount'],
                    'narration'       => $payload['narration'],
                    'currency'        => 'NGN',
                    'reference'       => $payload['reference'],
                    'debit_currency'  => 'NGN',
                ]);

            $body = $response->json();

            Log::info('Flutterwave vendor transfer initiated', [
                'reference' => $payload['reference'],
                'response'  => $body,
            ]);

            return [
                'status'  => ($body['status'] ?? '') === 'success' ? 'success' : 'failed',
                'message' => $body['message'] ?? 'Unknown response',
                'data'    => $body['data'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('Flutterwave transfer failed', ['error' => $e->getMessage()]);
            return ['status' => 'failed', 'message' => $e->getMessage(), 'data' => []];
        }
    }

    /**
     * Fetch the list of Nigerian banks supported by Flutterwave for transfers.
     */
    public function getBanks(): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->get($this->provider->base_url . '/banks/NG');

            if ($response->successful()) {
                $banks = $response->json('data') ?? [];
                return collect($banks)->map(fn($bank) => [
                    'code' => $bank['code'],
                    'name' => $bank['name'],
                ])->values()->all();
            }

            Log::error('Flutterwave: failed to fetch banks', ['error' => $response->body()]);
            return [];
        } catch (\Throwable $e) {
            Log::error('Flutterwave: getBanks exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    protected function callback(HttpRequest $request): array
    {
        $payload = $request->all();
        $data = $payload['data'];
        $customer = $data['customer'];
        $user = User::where('email', $customer['email'])->first();
        if (!$user) {
            Log::warning('FlutterWave webhook: no user found for email', ['email' => $customer['email'] ?? null]);
            return [];
        }

        $creditedAmount = $this->creditedAmount($data['amount']);
        $isSuccess = $data['status'] == "successful";

        // Gateways retry webhooks (missed 200, network hiccup, etc.) — only
        // credit the wallet the first time this tx_ref is seen as
        // successful, otherwise a retry double-credits the user.
        $alreadyCredited = Transaction::where('transaction_reference', $data['tx_ref'])
            ->where('status', 'success')
            ->exists();
        if ($isSuccess && !$alreadyCredited) {
            $user->wallet_balance += $creditedAmount;
            $user->save();
        }

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
            'status' => $isSuccess ? "success" : "fail",
            'receiver' => $customer['phone_number'] ?? null,
        ];
    }




}
