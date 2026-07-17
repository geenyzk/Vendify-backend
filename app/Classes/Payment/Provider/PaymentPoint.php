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
    private const VIRTUAL_ACCOUNT_BANK_CODES = ['20946', '20897'];

    // PaymentPoint provider name must match the providers.name value
    // in the database ("payment point"). creditedAmount() relies on
    // this matching to find the configured provider row.
    protected string $providerName = 'payment point';

    // Same host for every PaymentPoint instance — not collected per-provider.
    // NB: verify this matches PaymentPoint's current API base.
    protected function baseUrl(): string
    {
        return 'https://api.paymentpoint.co/api/v1';
    }

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
            $url = $this->baseUrl() . "/createVirtualAccount";
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

    /**
     * Create a reserved virtual account for an arbitrary customer identity
     * (not a parent User) — used when the parent generates accounts on behalf
     * of an affiliate child's customer. Returns the normalised account details,
     * or null on failure.
     *
     * @param array{email?:string,name?:string,username?:string,phone?:string} $customer
     * Returns EVERY bank account PaymentPoint issued (it returns one per bank
     * code in VIRTUAL_ACCOUNT_BANK_CODES — e.g. PalmPay and 9PSB), so the child
     * can offer each of its hard-coded bank options funded by the parent. This
     * used to keep only bankAccounts.0 and silently drop the rest.
     *
     * @return array<int, array{account_number:string,bank_name:?string,account_name:?string,reference:?string}>|null
     */
    public function generateForCustomer(array $customer): ?array
    {
        try {
            $payload = [
                'email'       => $customer['email'] ?? null,
                'name'        => $customer['name'] ?? ($customer['username'] ?? null),
                'phoneNumber' => $customer['phone'] ?? null,
                'bankCode'    => self::VIRTUAL_ACCOUNT_BANK_CODES,
                'businessId'  => $this->provider->username,
            ];

            $response = Http::withHeaders($this->getHeaders())
                ->post($this->baseUrl() . "/createVirtualAccount", $payload);

            Log::info('PaymentPoint: creating child virtual account', [
                'email' => $payload['email'],
                'ok' => $response->successful(),
            ]);

            if (!$response->successful()) {
                Log::error('PaymentPoint: child virtual account creation failed', ['body' => $response->body()]);
                return null;
            }

            $accounts = collect((array) $response->json('bankAccounts'))
                ->filter(fn ($b) => is_array($b) && !empty($b['accountNumber']))
                ->map(fn ($b) => [
                    'account_number' => $b['accountNumber'],
                    'bank_name'      => $b['bankName'] ?? null,
                    'account_name'   => $b['accountName'] ?? null,
                    'reference'      => $b['Reserved_Account_Id'] ?? null,
                ])
                ->values()
                ->all();

            return $accounts !== [] ? $accounts : null;
        } catch (\Throwable $e) {
            Log::error('PaymentPoint::generateForCustomer exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The receiving virtual account number on an incoming funding webhook, so
     * the parent can map it to a child virtual account. Best-effort across the
     * payload shapes PaymentPoint has been observed to send.
     */
    public function virtualAccountNumber(Request $request): ?string
    {
        $p = $request->all();

        return $p['account_number']
            ?? data_get($p, 'data.account_number')
            ?? data_get($p, 'customer.account_number')
            ?? data_get($p, 'virtual_account.account_number')
            ?? data_get($p, 'receiver.account_number')
            ?? $p['receiver_account_number']
            ?? null;
    }

    protected function formatPayload(array|User $payload, ?User $user = null): array
    {
        $user = $payload instanceof User ? $payload : $user;
        return [
            'email'       => $user->email,
            'name'        => $user->username,
            'phoneNumber' => $user->phone,
            'bankCode'    => self::VIRTUAL_ACCOUNT_BANK_CODES,
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
            'provider'     => $this->providerName,
            'account_name' => $bankAccount['accountName'] ?? '',
            'status'       => 'active',
            'amount'       => 0.00, // No amount in response, so default to 0
            'ref'          => $bankAccount['Reserved_Account_Id'] ?? null,
            'tx_ref'       => $txRef,
            'expired_at'   => now()->addYears(1),
        ];
    }


    // Parsing only — see PaymentBase::callback() docblock. Wallet crediting
    // and idempotency are centralized in PaymentBase::webhook().
    //
    // A non-'success' delivery returns [] (no transaction row at all),
    // matching this provider's original gate behavior — unlike FlutterWave/
    // Monnify, which record a 'fail' row. Left as-is since changing it isn't
    // needed to fix the financial bugs below and there's no confirmed doc
    // for PaymentPoint's failure payload shape to build a fail-row from.
    protected function callback(Request $request): array
    {
        $payload = $request->all();

        // Normalize incoming payload and guard against missing keys
        if (($payload['transaction_status'] ?? null) !== 'success') {
            return [];
        }

        $customer = $payload['customer'] ?? [];
        $creditedAmount = $this->creditedAmount($payload['amount_paid'] ?? 0);

        return [
            'user_email' => $customer['email'] ?? null,
            'provider' => $this->providerName,
            'transaction_reference' => $payload['transaction_id'] ?? data_get($payload, 'data.tx_ref'),
            'payment_reference' => null,
            'response_message' => $payload['description'] ?? data_get($payload, 'data.description', 'Transaction successful'),
            'completed_at' => now(),
            'funding_method' => 'bank_transfer',
            'service_fee' => $payload['settlement_fee'] ?? data_get($payload, 'data.app_fee', 0.00),
            'platform' => 'web',
            'transaction_type' => 'wallet_funding',
            'account_or_phone' => $customer['phone'] ?? ($customer['phone_number'] ?? null),
            'amount' => $creditedAmount,
            'status' => 'success',
            'receiver' => data_get($payload, 'receiver.account_number')
                ?? ($customer['phone'] ?? ($customer['phone_number'] ?? null)),
        ];
    }

    // PaymentPoint signs the raw JSON body with HMAC-SHA256 using the
    // dashboard security key and sends it in Paymentpoint-Signature.
    // Leaving webhook_access empty deliberately fails closed.
    protected function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->provider->webhook_access;
        if (empty($secret)) {
            Log::warning('PaymentPoint webhook secret (webhook_access) not configured — rejecting webhook.', [
                'provider_id' => $this->provider->id,
            ]);
            return false;
        }

        $received = $request->header('Paymentpoint-Signature');
        if (empty($received)) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $received);
    }

}
