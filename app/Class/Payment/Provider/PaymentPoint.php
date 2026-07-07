<?php

namespace App\Class\Payment\Provider;

use App\Class\Payment\PaymentBase;
use App\Models\Transaction;
use App\Models\User;
use App\Models\General;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentPoint extends PaymentBase
{
    // Was never declared — needed WITH the space to match the real
    // providers.name value ("payment point"), confirmed live via
    // SHOW COLUMNS/SELECT, so creditedAmount()'s Provider::whereName()
    // lookup actually finds a row instead of silently no-op'ing.
    protected string $providerName = 'payment point';

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
                $data = $response->json('data');
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
            'name'        => $user->fullname,
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
            'provider'     => $this->providerName,
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
        if (($payload['transaction_status'] ?? null) !== 'success') {
            return [];
        }

        $data = $payload['data'] ?? [];
        $customer = $payload['customer'] ?? [];
        $creditedAmount = $this->creditedAmount($payload['amount_paid'] ?? 0);

        return [
            'user_email' => $customer['email'] ?? null,
            'provider' => $this->providerName,
            'transaction_reference' => $data['tx_ref'] ?? null,
            // Was $data['flw_ref'] — copy-pasted from FlutterWave's callback,
            // not a real PaymentPoint field. Left null until confirmed
            // against PaymentPoint's actual webhook payload docs.
            'payment_reference' => null,
            'response_message' => $data['description'] ?? 'Transaction successful',
            'completed_at' => now(),
            "funding_method" => "bank_transfer",
            'service_fee' => $data['app_fee'] ?? 0.00,
            'platform' => 'web',
            'transaction_type' => 'wallet_funding',
            'account_or_phone' => $customer['phone_number'] ?? null,
            'amount' => $creditedAmount,
            // Was `$data['status'] ?? 'failed'` — 'failed' isn't a valid
            // enum value on transactions.status ('pending'|'success'|'fail'),
            // and $data['status'] isn't a field this payload has (the real
            // gate is the outer transaction_status, already checked above),
            // so this always fell through to the invalid 'failed' literal.
            'status' => 'success',
            'receiver' => $customer['phone_number'] ?? null,
        ];
    }

    // No confirmed PaymentPoint webhook signature documentation was
    // available at implementation time. Best-effort scheme: expects a
    // `x-paymentpoint-signature` header containing an HMAC-SHA256 hex
    // digest of the raw request body, keyed by the provider's configured
    // webhook secret (webhook_access column). MUST be verified against
    // PaymentPoint's real dashboard/docs before relying on this — until
    // then, populating webhook_access is what activates verification at
    // all; leaving it empty makes every PaymentPoint webhook rejected
    // (fails closed, not open).
    protected function verifyWebhookSignature(Request $request): bool
    {
        $secret = $this->provider->webhook_access;
        if (empty($secret)) {
            Log::warning('PaymentPoint webhook secret (webhook_access) not configured — rejecting webhook.', [
                'provider_id' => $this->provider->id,
            ]);
            return false;
        }

        $received = $request->header('x-paymentpoint-signature');
        if (empty($received)) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $received);
    }

}
