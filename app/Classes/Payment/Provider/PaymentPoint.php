<?php

namespace App\Classes\Payment\Provider;

use App\Classes\Payment\PaymentBase;
use App\Models\Transaction;
use App\Models\User;
use App\Models\General;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentPoint extends PaymentBase
{
    // See Monnify.php for why this is required — creditedAmount() reads
    // this property and throws if it's left uninitialized, silently
    // breaking wallet crediting for every successful payment.
    protected string $providerName = 'paymentpoint';

    public function connect(): mixed
    {
        return '';
    }

    public function checkBalance(): string
    {
        return '';
    }

    protected function getHeaders(): array
    {
        return [
            "Authorization" => "Bearer {$this->provider->password}", // token
            "api-key"       => $this->provider->api_key,              // public API key
            "Content-Type"  => "application/json",
        ];
    }

    public function generate(User $user): array|null
    {
        try {
            $payload = $this->formatPayload($user);
            $url = $this->provider->base_url . "/createVirtualAccount";
            // Log()
            $response = Http::withHeaders($this->getHeaders())->post($url, $payload);

            Log::info("PaymentPoint: Creating virtual account for {$user->email}", [
                'payload' => $payload,
                'response' => $response->json(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $this->formatResponse($data, $user);
            } else {
                Log::error("PaymentPoint: Failed to create virtual account", [
                    'body' => $response->body(),
                ]);
                return null;
            }

        } catch (\Throwable $e) {
            Log::error("PaymentPoint::generate exception: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    protected function formatPayload(array|User $payload, ?User $user = null): array
    {
        $user = $payload instanceof User ? $payload : $user;
        return [
            'email'       => $user->email,
            'name'        => $user->username,
            'phoneNumber' => $user->phone,
            'bankCode'    => ['20946'],
            'businessId'  => $this->provider->username,
        ];
    }

    protected function formatResponse(array $data, User $user): array
    {
        $txRef = \App\Models\Transaction::generateTransactionId();

        $bankAccount = $data['bankAccounts'][0] ?? null;

        return [
            'user_id'      => $user->id,
            'account_type' => 'virtual',
            'bank_account' => $bankAccount['accountNumber'] ?? '',
            'bank_name'    => $bankAccount['bankName'] ?? '',
            'provider'     => 'paymentpoint',
            "account_name" => $bankAccount['accountName'],
            'status'       => 'active',
            'amount'       => 0.00, // No amount in response, so default to 0
            'ref'          => $bankAccount['Reserved_Account_Id'] ?? null,
            'tx_ref'       => $txRef,
            'expired_at'   => now()->addYears(1),
        ];
    }


    protected function callback(Request $request): array
    {
        $payload = $request->all();
        if($payload['transaction_status'] !== 'success') return [];
        $data = $payload['data'];
        $customer = $payload['customer'];
        $user = User::where('email', $customer['email'])->first();
        if (!$user) {
            Log::warning('PaymentPoint webhook: no user found for email', ['email' => $customer['email'] ?? null]);
            return [];
        }

        $creditedAmount = $this->creditedAmount($payload['amount_paid']);

        // Gateways retry webhooks (missed 200, network hiccup, etc.) — only
        // credit the wallet the first time this tx_ref is seen as
        // successful, otherwise a retry double-credits the user.
        $alreadyCredited = Transaction::where('transaction_reference', $data['tx_ref'])
            ->where('status', 'success')
            ->exists();
        if (!$alreadyCredited) {
            $user->wallet_balance += $creditedAmount;
            $user->save();
        }

        return [
            'user_id' => $user->id,
            'provider' => $this->providerName,
            'transaction_reference' =>  $data['tx_ref'] ,
            'payment_reference' => $data['flw_ref'] ?? null,
            'response_message' => $data['description'] ?? 'Transaction failed',
            'completed_at' => now(),
            "funding_method" => "bank_transfer",
            'service_fee' => $data['app_fee'] ?? 0.00,
            'platform' => 'web',
            'transaction_type' => 'wallet_funding',
            'account_or_phone' => $customer['phone_number'] ?? null,
            'amount' => $creditedAmount ?? 0.00,
            'status' => $data['status'] ?? 'failed',
            'receiver' => $customer['phone_number'] ?? null,
        ];
    }


}
