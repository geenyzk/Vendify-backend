<?php

namespace App\Classes\Payment\Provider;

use App\Classes\Payment\InvalidPaymentWebhookException;
use App\Classes\Payment\PaymentBase;
use App\Classes\Payment\WebhookOutcome;
use App\Models\Bank;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletWithdrawal;
use App\Services\Payments\WalletWithdrawalSettlementService;
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
                ->connectTimeout(5)
                ->timeout(15)
                ->get($this->baseUrl() . '/balances');
        if ($response->successful()) {
            $account = collect($response->json('data') ?? [])->first(
                fn ($balance) => strtoupper((string) ($balance['currency'] ?? '')) === 'NGN'
            );
            return (string) ($account['available_balance'] ?? '');
        }
        return '0';
    }



    public function generate($payload):array|null
    {
        try {
            if (empty($payload->bvn) && empty($payload->nin)) {
                Log::warning('Flutterwave permanent account provisioning skipped: customer identity is unavailable.', [
                    'user_id' => $payload->id,
                    'operation' => 'virtual_account_create',
                ]);
                return null;
            }
            $payloadResponse = $this->formatPayload($payload);
            $response = Http::withHeaders($this->getHeaders())
                ->connectTimeout(5)
                ->timeout(15)
                ->post($this->baseUrl() . "/virtual-account-numbers", $payloadResponse);

            if ($response->successful()) {
                $data = $response->json('data');
                Log::info('Flutterwave virtual account created.', [
                    'user_id' => $payload->id,
                    'operation' => 'virtual_account_create',
                    'status' => 'success',
                    'transaction_reference' => $payloadResponse['tx_ref'],
                ]);
                return $this->formatResponse(array_merge($data, $payloadResponse), $payload);
            } else {
                Log::error("Failed to generate account.", [
                    'operation' => 'virtual_account_create',
                    'status' => $response->status(),
                ]);
                return null;
            }
        } catch (\Throwable $th) {
            Log::error('Flutterwave virtual account creation failed.', [
                'operation' => 'virtual_account_create',
                'error_category' => class_basename($th),
            ]);

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
        $request = [
            "email" => $sessionUser->email,
            "tx_ref" => $txRef,
            "phonenumber" => $sessionUser->phone,
            "currency" => 'NGN',
            "is_permanent" => true,
            "firstname" => $firstName,
            "lastname" => $lastName,
        ];
        if (!empty($sessionUser->bvn)) {
            $request['bvn'] = $sessionUser->bvn;
        } elseif (!empty($sessionUser->nin)) {
            $request['nin'] = $sessionUser->nin;
        }
        return $request;
    }

    function formatResponse(array $data, ?User $user = null): array
    {



        return  [
            'user_id' => $user->id,
            'account_type' => 'virtual',
            'bank_account' => $data['account_number'],
            'bank_name' => $data['bank_name'],
            'account_name' => $data['account_name'] ?? trim($user->fullname),
            'provider' => $this->providerName,
            'status' => 'active',
            'amount' => $data['amount'] ?? 0.00,
            'ref' => $data['flw_ref'] ?? null,
            'tx_ref' => $data['tx_ref'],
            'currency' => 'NGN',
            'expired_at' => null,
        ];
    }
    /**
     * Push funds to a vendor's bank account via Flutterwave Transfers API.
     */
    public function transfer(array $payload): array
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->connectTimeout(5)
                ->timeout(20)
                ->post($this->baseUrl() . '/transfers', [
                    'account_bank'    => $payload['account_bank'],
                    'account_number'  => $payload['account_number'],
                    'amount'          => $payload['amount'],
                    'narration'       => $payload['narration'],
                    'currency'        => 'NGN',
                    'reference'       => $payload['reference'],
                    'debit_currency'  => 'NGN',
                    'callback_url'    => $this->provider->webhook,
                ]);

            $body = $response->json();

            if ($response->serverError() || in_array($response->status(), [408, 429], true)) {
                throw new \RuntimeException('Flutterwave transfer service is temporarily unavailable.');
            }

            $accepted = $response->successful() && ($body['status'] ?? '') === 'success';
            $providerStatus = strtoupper((string) data_get($body, 'data.status', ''));

            Log::info('Flutterwave transfer initiation completed.', [
                'operation' => 'transfer_create',
                'reference' => $payload['reference'],
                'status' => $accepted ? ($providerStatus ?: 'ACCEPTED') : 'REJECTED',
                'flutterwave_transfer_id' => data_get($body, 'data.id'),
            ]);

            return [
                'status'  => $accepted ? 'processing' : 'failed',
                'message' => $body['message'] ?? 'Unknown response',
                'data'    => $body['data'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('Flutterwave transfer initiation failed.', [
                'operation' => 'transfer_create',
                'reference' => $payload['reference'] ?? null,
                'error_category' => class_basename($e),
            ]);
            throw $e;
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

            Log::error('Flutterwave bank lookup failed.', ['operation' => 'bank_lookup', 'status' => $response->status()]);
            return [];
        } catch (\Throwable $e) {
            Log::error('Flutterwave bank lookup threw.', ['operation' => 'bank_lookup', 'error_category' => class_basename($e)]);
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

    protected function verifiedCallback(HttpRequest $request): array
    {
        if ($request->input('event') !== 'charge.completed') {
            throw new InvalidPaymentWebhookException('Unsupported Flutterwave deposit event.');
        }

        $webhook = $request->input('data');
        $transactionId = is_array($webhook) ? ($webhook['id'] ?? null) : null;
        if (!$transactionId) {
            throw new InvalidPaymentWebhookException('Flutterwave transaction id is missing.');
        }

        $response = Http::withHeaders($this->getHeaders())
            ->connectTimeout(5)
            ->timeout(15)
            ->get($this->baseUrl() . "/transactions/{$transactionId}/verify");

        if ($response->serverError()) {
            throw new \RuntimeException('Flutterwave verification is temporarily unavailable.');
        }
        if (!$response->successful() || $response->json('status') !== 'success' || !is_array($response->json('data'))) {
            throw new InvalidPaymentWebhookException('Flutterwave could not verify the transaction.');
        }

        $verified = $response->json('data');
        if ((string) ($verified['id'] ?? '') !== (string) $transactionId
            || (string) ($verified['id'] ?? '') !== (string) ($webhook['id'] ?? '')) {
            throw new InvalidPaymentWebhookException('Flutterwave transaction identity mismatch.');
        }
        if (($verified['status'] ?? null) !== 'successful') {
            throw new InvalidPaymentWebhookException('Flutterwave transaction is not successful.');
        }
        if (strtoupper((string) ($verified['currency'] ?? '')) !== 'NGN') {
            throw new InvalidPaymentWebhookException('Flutterwave transaction currency is not NGN.');
        }

        $verifiedAmount = (float) ($verified['amount'] ?? 0);
        if ($verifiedAmount <= 0 || round($verifiedAmount, 2) !== round((float) ($webhook['amount'] ?? 0), 2)) {
            throw new InvalidPaymentWebhookException('Flutterwave transaction amount mismatch.');
        }

        $txRef = (string) ($verified['tx_ref'] ?? '');
        if ($txRef === '' || !hash_equals($txRef, (string) ($webhook['tx_ref'] ?? ''))) {
            throw new InvalidPaymentWebhookException('Flutterwave merchant reference mismatch.');
        }
        $flwRef = (string) ($verified['flw_ref'] ?? '');
        if ($flwRef === '' || !hash_equals($flwRef, (string) ($webhook['flw_ref'] ?? ''))) {
            throw new InvalidPaymentWebhookException('Flutterwave payment reference mismatch.');
        }

        $account = Bank::where('provider', $this->providerName)
            ->where('tx_ref', $txRef)
            ->where('status', 'active')
            ->first();
        if (!$account) {
            throw new InvalidPaymentWebhookException('Flutterwave reference does not map to a Vendify account.');
        }

        $user = User::find($account->user_id);
        $verifiedEmail = strtolower(trim((string) data_get($verified, 'customer.email', '')));
        if (!$user || $verifiedEmail === '' || $verifiedEmail !== strtolower(trim((string) $user->email))) {
            throw new InvalidPaymentWebhookException('Flutterwave payment owner mismatch.');
        }

        Log::info('Flutterwave deposit verified.', [
            'operation' => 'transaction_verify',
            'status' => 'successful',
            'flutterwave_transaction_id' => (string) $transactionId,
            'transaction_reference' => $txRef,
        ]);

        return [
            'user_id' => $user->id,
            'provider' => $this->providerName,
            'provider_transaction_id' => (string) $transactionId,
            'transaction_reference' => 'FLW-DEP-' . $transactionId,
            'payment_reference' => $flwRef,
            'response_message' => $verified['processor_response'] ?? 'Transaction successful',
            'completed_at' => now(),
            'funding_method' => 'bank_transfer',
            'service_fee' => $verified['app_fee'] ?? 0.00,
            'platform' => 'web',
            'transaction_type' => 'wallet_funding',
            'account_or_phone' => data_get($verified, 'customer.phone_number'),
            'amount' => $this->creditedAmount($verifiedAmount),
            'status' => 'success',
            'receiver' => data_get($verified, 'customer.phone_number'),
        ];
    }

    protected function handleProviderEvent(HttpRequest $request): ?WebhookOutcome
    {
        if ($request->input('event') !== 'transfer.completed') {
            return null;
        }

        $transferId = $request->input('data.id');
        if (!$transferId) {
            throw new InvalidPaymentWebhookException('Flutterwave transfer id is missing.');
        }

        $verified = $this->verifyTransfer($transferId);
        $this->settleWithdrawal($verified);

        return WebhookOutcome::Accepted;
    }

    public function verifyTransfer(string|int $transferId): array
    {
        $response = Http::withHeaders($this->getHeaders())
            ->connectTimeout(5)
            ->timeout(15)
            ->get($this->baseUrl() . "/transfers/{$transferId}");

        if ($response->serverError()) {
            throw new \RuntimeException('Flutterwave transfer verification is temporarily unavailable.');
        }
        if (!$response->successful() || $response->json('status') !== 'success' || !is_array($response->json('data'))) {
            throw new InvalidPaymentWebhookException('Flutterwave transfer could not be verified.');
        }

        return $response->json('data');
    }

    public function findTransferByReference(string $reference): ?array
    {
        $response = Http::withHeaders($this->getHeaders())
            ->connectTimeout(5)
            ->timeout(15)
            ->get($this->baseUrl() . '/transfers', ['reference' => $reference, 'page_size' => 10]);
        if ($response->serverError()) {
            throw new \RuntimeException('Flutterwave transfer lookup is temporarily unavailable.');
        }
        if (!$response->successful()) {
            return null;
        }

        return collect($response->json('data') ?? [])->first(
            fn ($transfer) => (string) ($transfer['reference'] ?? '') === $reference
        );
    }

    public function settleWithdrawal(array $verified): void
    {
        $reference = (string) ($verified['reference'] ?? '');
        $withdrawal = WalletWithdrawal::where('transaction_reference', $reference)
            ->orWhere('gateway_reference', $reference)
            ->first();
        if (!$withdrawal) {
            throw new InvalidPaymentWebhookException('Flutterwave transfer does not map to a Vendify withdrawal.');
        }
        if ((string) ($verified['id'] ?? '') === ''
            || ($withdrawal->gateway_transfer_id && (string) $withdrawal->gateway_transfer_id !== (string) $verified['id'])
            || strtoupper((string) ($verified['currency'] ?? '')) !== 'NGN'
            || round((float) ($verified['amount'] ?? 0), 2) !== round((float) $withdrawal->amount, 2)) {
            throw new InvalidPaymentWebhookException('Flutterwave transfer details do not match the withdrawal.');
        }

        $settlement = app(WalletWithdrawalSettlementService::class);
        $status = strtoupper((string) ($verified['status'] ?? ''));
        if ($status === 'SUCCESSFUL') {
            $settlement->markSuccessful($withdrawal, (string) $verified['id'], $reference);
        } elseif ($status === 'FAILED') {
            $settlement->markFailed($withdrawal, (string) ($verified['complete_message'] ?? 'Flutterwave transfer failed.'));
        } else {
            $withdrawal->update([
                'status' => WalletWithdrawal::STATUS_PROCESSING,
                'gateway_transfer_id' => (string) $verified['id'],
                'gateway_reference' => $reference,
            ]);
        }
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
