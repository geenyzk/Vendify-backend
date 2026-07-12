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
        $balance = $this->checkBalance() ;
        return $balance !== "" ? true: false;
    }

    function checkBalance(): string
    {

        $response = Http::withHeaders($this->getHeaders())
                ->get($this->baseUrl() . '/balances/NG');
        if ($response->successful()) {
                $account = $response->json('data') ?? [];
                return $account['available_balance'] ?? "";
            }
        return 0;
    }



    public function generate($payload):array|null
    {
        try {
            $payloadResponse = $this->formatPayload($payload);
            $response = Http::withHeaders($this->getHeaders())
                ->post($this->baseUrl() . "/virtual-account-numbers", $payloadResponse);

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


    // Same host for every Flutterwave instance — the base URL isn't collected
    // per-provider (only the credentials are), so it lives here in the class.
    protected function baseUrl(): string
    {
        return 'https://api.flutterwave.com/v3';
    }

    protected function getHeaders(): array
    {
        // v3 authenticates with the Secret key.
        return [
            "Authorization" => "Bearer " . $this->provider->secret_key,
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
    /**
     * Push funds to a vendor's bank account via Flutterwave Transfers API.
     */
    public function transfer(array $payload): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->post($this->baseUrl() . '/transfers', [
                    'account_bank'    => $payload['account_bank'],
                    'account_number'  => $payload['account_number'],
                    'amount'          => $payload['amount'],
                    'narration'       => $payload['narration'],
                    'currency'        => 'NGN',
                    'reference'       => $payload['reference'],
                    'debit_currency'  => 'NGN',
                ]);

            $body = $response->json();

            // Log::info('Flutterwave vendor transfer initiated', [
            //     'reference' => $payload['reference'],
            //     'response'  => $body,
            // ]);

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

    public function supportsTransfers(): bool
    {
        return true;
    }

    /**
     * Fetch the list of Nigerian banks supported by Flutterwave for transfers.
     */
    public function getBanks(): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->get($this->baseUrl() . '/banks/NG');

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
            'funding_method' => 'bank_transfer',
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
