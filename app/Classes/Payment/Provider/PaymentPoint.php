<?php

namespace App\Classes\Payment\Provider;

use App\Classes\Payment\PaymentBase;
use App\Classes\Payment\PaymentFactory;
use App\Models\PaymentPointProvisioning;
use App\Models\Transaction;
use App\Models\User;
use App\Models\General;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentPoint extends PaymentBase
{
    private const SUPPORTED_VIRTUAL_ACCOUNT_BANK_CODES = ['20946', '20897'];

    /** @var array{id_type:string,id_number:string}|null */
    private ?array $identity = null;

    private bool $explicitKycAttempt = false;

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

    public function generateAccount(User $user): string
    {
        $state = PaymentPointProvisioning::query()
            ->where('user_id', $user->id)
            ->where('provider_id', $this->provider->id)
            ->first();

        if (!$this->explicitKycAttempt && $state?->status === PaymentPointProvisioning::STATUS_KYC_REQUIRED) {
            return 'identity_required';
        }

        if (!$this->explicitKycAttempt && $state?->status === PaymentPointProvisioning::STATUS_FAILED) {
            return 'provider_failed_no_retry';
        }

        $outcome = parent::generateAccount($user);

        if (in_array($outcome, ['created', 'already_ready'], true)) {
            $this->updateProvisioningState(
                $user,
                PaymentPointProvisioning::STATUS_READY,
                null,
                false,
                $outcome === 'created',
            );
        } elseif ($outcome === 'persistence_failed') {
            $this->updateProvisioningState($user, PaymentPointProvisioning::STATUS_FAILED, 'persistence_failed');
        }

        $state = PaymentPointProvisioning::query()
            ->where('user_id', $user->id)
            ->where('provider_id', $this->provider->id)
            ->first();

        return match ($state?->status) {
            PaymentPointProvisioning::STATUS_KYC_REQUIRED => 'identity_required',
            PaymentPointProvisioning::STATUS_FAILED => 'provider_failed_no_retry',
            default => $outcome,
        };
    }

    public function generateWithIdentity(User $user, string $idType, string $idNumber): string
    {
        if (!config('paymentpoint.kyc_recovery_enabled')) {
            return 'kyc_recovery_unconfirmed';
        }

        $claim = DB::transaction(function () use ($user): string {
            $state = PaymentPointProvisioning::query()
                ->where('user_id', $user->id)
                ->where('provider_id', $this->provider->id)
                ->lockForUpdate()
                ->first();

            if (!$state || $state->status !== PaymentPointProvisioning::STATUS_KYC_REQUIRED) {
                return 'identity_not_requested';
            }

            if ($state->kyc_attempted_at !== null) {
                return 'kyc_attempt_already_used';
            }

            $state->forceFill([
                'kyc_consent_at' => now(),
                'kyc_attempted_at' => now(),
            ])->save();

            return 'claimed';
        });

        if ($claim !== 'claimed') {
            return $claim;
        }

        $this->identity = ['id_type' => $idType, 'id_number' => $idNumber];
        $this->explicitKycAttempt = true;

        try {
            return $this->generateAccount($user);
        } finally {
            // The identity number is request-scoped and is never persisted.
            $this->identity = null;
            $this->explicitKycAttempt = false;
        }
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
        $startedAt = microtime(true);

        try {
            if (!$this->hasRequiredCredentials()) {
                return null;
            }

            $payload = $this->formatPayload($user);
            $url = $this->baseUrl() . "/createVirtualAccount";
            Log::info('funding_account.provider_request', [
                'stage' => 'provider_request',
                'provider' => 'paymentpoint',
                'provider_id' => $this->provider->id,
                'user_id' => $user->id,
                'endpoint' => '/api/v1/createVirtualAccount',
                'has_email' => trim((string) $payload['email']) !== '',
                'has_name' => trim((string) $payload['name']) !== '',
                'has_phone_number' => trim((string) $payload['phoneNumber']) !== '',
                'bank_codes' => $payload['bankCode'],
                'has_business_id' => trim((string) $payload['businessId']) !== '',
                'has_identity' => isset($payload['idType'], $payload['idNumber']),
            ]);

            $response = Http::connectTimeout(5)
                ->timeout(20)
                ->withHeaders($this->getHeaders())
                ->post($url, $payload);
            $data = $response->json();
            $data = is_array($data) ? $data : [];
            $diagnostics = $this->redactedResponseDiagnostics($data);

            Log::info('funding_account.provider_response', [
                'stage' => 'provider_response',
                'provider' => 'paymentpoint',
                'provider_id' => $this->provider->id,
                'user_id' => $user->id,
                'http_status' => $response->status(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'provider_status' => $data['status'] ?? null,
                'response_fields' => array_keys($data),
                'bank_account_count' => is_array($data['bankAccounts'] ?? null) ? count($data['bankAccounts']) : 0,
                'error_count' => is_array($data['errors'] ?? null) ? count($data['errors']) : 0,
                ...$diagnostics,
            ]);

            if ($response->successful()) {
                if (($data['status'] ?? null) !== 'success') {
                    $this->updateProvisioningState($user, PaymentPointProvisioning::STATUS_FAILED, 'provider_rejected');
                    Log::warning('funding_account.provider_rejected', [
                        'stage' => 'provider_response',
                        'provider' => 'paymentpoint',
                        'user_id' => $user->id,
                        'http_status' => $response->status(),
                        'provider_status' => $data['status'] ?? null,
                    ]);

                    return null;
                }

                $accounts = $this->usableBankAccounts($data);
                if ($accounts === []) {
                    $requiresIdentity = !$this->explicitKycAttempt
                        && is_array($data['errors'] ?? null)
                        && $data['errors'] !== [];
                    $this->updateProvisioningState(
                        $user,
                        $requiresIdentity
                            ? PaymentPointProvisioning::STATUS_KYC_REQUIRED
                            : PaymentPointProvisioning::STATUS_FAILED,
                        $diagnostics['failure_category'],
                        (bool) $diagnostics['customer_id_present'],
                    );
                    Log::warning('funding_account.provider_response_unusable', [
                        'stage' => 'provider_response',
                        'provider' => 'paymentpoint',
                        'user_id' => $user->id,
                        'http_status' => $response->status(),
                        'bank_account_count' => is_array($data['bankAccounts'] ?? null) ? count($data['bankAccounts']) : 0,
                        'failure_category' => $diagnostics['failure_category'],
                    ]);

                    return null;
                }

                $data['bankAccounts'] = $accounts;
                Log::info('funding_account.provider_account_ready', [
                    'stage' => 'provider_response',
                    'provider' => 'paymentpoint',
                    'user_id' => $user->id,
                    'accounts_count' => count($accounts),
                ]);

                return $this->formatResponse($data, $user);
            } else {
                $this->updateProvisioningState($user, PaymentPointProvisioning::STATUS_FAILED, 'provider_http_failed');
                Log::error('funding_account.provider_http_failed', [
                    'stage' => 'provider_response',
                    'provider' => 'paymentpoint',
                    'user_id' => $user->id,
                    'http_status' => $response->status(),
                ]);
                return null;
            }

        } catch (\Throwable $e) {
            $this->updateProvisioningState($user, PaymentPointProvisioning::STATUS_FAILED, 'provider_exception');
            Log::error('funding_account.provider_exception', [
                'stage' => 'provider_request',
                'provider' => 'paymentpoint',
                'provider_id' => $this->provider->id,
                'user_id' => $user->id,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'exception' => $e::class,
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
            if (!$this->hasRequiredCredentials()) {
                return null;
            }

            $payload = [
                'email'       => $customer['email'] ?? null,
                'name'        => $customer['name'] ?? ($customer['username'] ?? null),
                'phoneNumber' => $customer['phone'] ?? null,
                'bankCode'    => $this->virtualAccountBankCodes(),
                'businessId'  => $this->provider->username,
            ];

            $response = Http::withHeaders($this->getHeaders())
                ->post($this->baseUrl() . "/createVirtualAccount", $payload);

            Log::info('PaymentPoint: creating child virtual account', [
                'ok' => $response->successful(),
            ]);

            if (! $response->successful()) {
                $data = $response->json();
                $data = is_array($data) ? $data : [];
                Log::error('PaymentPoint: child virtual account creation failed', [
                    'http_status' => $response->status(),
                    ...$this->redactedResponseDiagnostics($data),
                ]);
                return null;
            }

            $data = $response->json();
            if (($data['status'] ?? null) !== 'success') {
                Log::warning('PaymentPoint rejected child virtual account creation.', [
                    'status' => $data['status'] ?? null,
                    ...$this->redactedResponseDiagnostics($data),
                ]);

                return null;
            }

            $accounts = collect($this->usableBankAccounts($data))
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
            Log::error('PaymentPoint::generateForCustomer exception.', [
                'exception' => $e::class,
            ]);
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
        $formatted = [
            'email'       => $user->email,
            'name'        => trim((string) ($user->fullname ?: $user->username)),
            'phoneNumber' => $user->phone,
            'bankCode'    => $this->virtualAccountBankCodes(),
            'businessId'  => $this->provider->username,
        ];

        if ($this->identity !== null) {
            $formatted['idType'] = $this->identity['id_type'];
            $formatted['idNumber'] = $this->identity['id_number'];
        }

        return $formatted;
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

    /** PaymentPoint requires all three values for createVirtualAccount. */
    private function hasRequiredCredentials(): bool
    {
        $missing = PaymentFactory::missingRequiredCredentials($this->provider);

        if ($missing !== []) {
            Log::warning('PaymentPoint virtual account credentials are incomplete.', [
                'provider_id' => $this->provider->id,
                'missing_fields' => $missing,
            ]);

            return false;
        }

        return true;
    }

    /** @return array<int, string> */
    private function virtualAccountBankCodes(): array
    {
        $configured = config('paymentpoint.virtual_account_bank_codes', ['20946']);
        $configured = is_array($configured) ? $configured : [];

        $enabled = array_values(array_unique(array_intersect(
            self::SUPPORTED_VIRTUAL_ACCOUNT_BANK_CODES,
            array_map(static fn ($code) => trim((string) $code), $configured),
        )));

        return $enabled !== [] ? $enabled : ['20946'];
    }

    private function updateProvisioningState(
        User $user,
        string $status,
        ?string $errorCategory,
        bool $customerCreated = false,
        bool $incrementAttempt = true,
    ): void {
        if (!$user->exists || !$this->provider->exists) {
            return;
        }

        $state = PaymentPointProvisioning::query()->firstOrNew([
            'user_id' => $user->id,
            'provider_id' => $this->provider->id,
        ]);

        $state->status = $status;
        $state->last_error_category = $errorCategory;
        $state->customer_created = $state->customer_created || $customerCreated;

        if ($incrementAttempt) {
            $state->attempt_count = min(255, ((int) $state->attempt_count) + 1);
            $state->last_attempt_at = now();
        }

        $state->save();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function usableBankAccounts(array $data): array
    {
        return collect((array) ($data['bankAccounts'] ?? []))
            ->filter(fn ($account) => is_array($account)
                && trim((string) ($account['accountNumber'] ?? '')) !== ''
                && trim((string) ($account['bankName'] ?? '')) !== '')
            ->values()
            ->all();
    }

    /**
     * PaymentPoint documents an errors array but not the shape of its entries.
     * Keep diagnostics useful without persisting free-form provider text or
     * customer identifiers. Only values from semantic, allowlisted keys are
     * emitted, and validation fields/bank codes are restricted to values this
     * integration already knows how to send.
     *
     * @return array<string, mixed>
     */
    private function redactedResponseDiagnostics(array $data): array
    {
        $errors = is_array($data['errors'] ?? null) ? array_values($data['errors']) : [];
        $codes = [];
        $categories = [];
        $fields = [];
        $bankCodes = [];
        $itemTypes = [];
        $hasUnclassifiedFields = false;

        $knownFields = [
            'email' => 'email',
            'name' => 'name',
            'phonenumber' => 'phone_number',
            'phone_number' => 'phone_number',
            'bankcode' => 'bank_code',
            'bank_code' => 'bank_code',
            'businessid' => 'business_id',
            'business_id' => 'business_id',
            'idtype' => 'id_type',
            'id_type' => 'id_type',
            'idnumber' => 'id_number',
            'id_number' => 'id_number',
        ];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                $itemTypes[] = get_debug_type($error);

                continue;
            }

            $itemTypes[] = 'object';
            $recognizedKeys = 0;

            foreach (['code', 'errorCode', 'error_code'] as $key) {
                if (!array_key_exists($key, $error)) {
                    continue;
                }

                $recognizedKeys++;
                if ($token = $this->safeDiagnosticToken($error[$key])) {
                    $codes[] = $token;
                }
            }

            foreach (['category', 'type'] as $key) {
                if (!array_key_exists($key, $error)) {
                    continue;
                }

                $recognizedKeys++;
                if ($token = $this->safeDiagnosticToken($error[$key])) {
                    $categories[] = $token;
                }
            }

            foreach (['field', 'path', 'parameter', 'param'] as $key) {
                if (!array_key_exists($key, $error)) {
                    continue;
                }

                $recognizedKeys++;
                $normalized = strtolower(str_replace(['.', '-', ' '], '_', trim((string) $error[$key])));
                $compact = str_replace('_', '', $normalized);
                if (isset($knownFields[$normalized])) {
                    $fields[] = $knownFields[$normalized];
                } elseif (isset($knownFields[$compact])) {
                    $fields[] = $knownFields[$compact];
                }
            }

            foreach (['bankCode', 'bank_code'] as $key) {
                if (!array_key_exists($key, $error)) {
                    continue;
                }

                $recognizedKeys++;
                $bankCode = trim((string) $error[$key]);
                if (in_array($bankCode, self::SUPPORTED_VIRTUAL_ACCOUNT_BANK_CODES, true)) {
                    $bankCodes[] = $bankCode;
                }
            }

            $hasUnclassifiedFields = $hasUnclassifiedFields || count($error) > $recognizedKeys;
        }

        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $customerIdPresent = trim((string) ($customer['customer_id'] ?? '')) !== '';
        $usableBankAccountCount = count($this->usableBankAccounts($data));

        $failureCategory = match (true) {
            $usableBankAccountCount > 0 => 'none',
            $customerIdPresent && $errors !== [] => 'customer_created_bank_generation_failed',
            $errors !== [] => 'provider_error_unclassified',
            default => 'missing_usable_bank_account',
        };

        return [
            'provider_message_present' => trim((string) ($data['message'] ?? '')) !== '',
            'customer_record_present' => $customer !== [],
            'customer_id_present' => $customerIdPresent,
            'error_item_types' => array_values(array_unique($itemTypes)),
            'error_codes' => array_values(array_unique($codes)),
            'error_categories' => array_values(array_unique($categories)),
            'error_fields' => array_values(array_unique($fields)),
            'error_bank_codes' => array_values(array_unique($bankCodes)),
            'has_unclassified_error_fields' => $hasUnclassifiedFields,
            'usable_bank_account_count' => $usableBankAccountCount,
            'failure_category' => $failureCategory,
        ];
    }

    private function safeDiagnosticToken(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,63}$/', $value) === 1
            ? $value
            : null;
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
        if (in_array(trim((string) $secret), ['', '#', '1'], true)) {
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
