<?php

namespace App\Services\AirtimeToCash\Providers;

use App\Services\AirtimeToCash\AirtimeToCashProviderInterface;
use App\Services\AirtimeToCash\ChecksQuota;
use App\Services\AirtimeToCash\ChecksSession;
use App\Services\AirtimeToCash\ProviderCallTrace;
use App\Services\AirtimeToCash\ProviderHealthResult;
use App\Services\AirtimeToCash\ProviderRequestNotSent;
use App\Services\AirtimeToCash\ProviderResult;
use App\Services\AirtimeToCash\ProviderTransport;

final class AutomationProvider implements AirtimeToCashProviderInterface, ChecksQuota, ChecksSession
{
    /** Generic words that may be logged from quota prose. Digits, names and tokens never match. */
    private const QUOTA_TERMS = ['recipient', 'recipients', 's', 'available', 'unavailable', 'unavailability', 'service',
        'not', 'no', 'quota', 'insufficient', 'exhausted', 'limit', 'amount', 'network', 'moment', 'currently', 'invalid', 'error'];

    /**
     * Generic English words that may be logged from transfer failure prose. Everything else,
     * including any token containing a digit, is masked; no names, brands or identifiers.
     */
    private const TRANSFER_TERMS = [
        // Outcome and ability.
        'not', 'no', 'unable', 'able', 'cannot', 'can', 'could', 'failed', 'fail', 'failure', 'successful', 'success',
        'successfully', 'completed', 'complete', 'processed', 'pending', 'declined', 'denied', 'rejected', 'error',
        // Permission and eligibility.
        'allowed', 'permitted', 'eligible', 'enabled', 'disabled', 'activated', 'active', 'inactive', 'registered',
        'restricted', 'barred', 'suspended', 'blocked', 'locked', 'available', 'unavailable', 'supported', 'authorized',
        // Subjects.
        'transfer', 'transfers', 'transaction', 'airtime', 'recipient', 'sender', 'network', 'sim', 'line', 'number',
        'subscriber', 'customer', 'account', 'service', 'tariff', 'plan', 'prepaid', 'postpaid', 'session', 'pin', 'otp',
        'balance', 'credit', 'fund', 'funds', 'amount', 'request', 'reference', 'duplicate',
        // Qualifiers.
        'invalid', 'valid', 'incorrect', 'correct', 'wrong', 'mismatch', 'insufficient', 'sufficient', 'enough', 'low',
        'expired', 'found', 'login', 'unknown', 'limit', 'exceeded', 'reached', 'daily', 'maximum', 'minimum', 'default',
        'change', 'already', 'used', 'own', 'same', 'yet', 'try', 'again', 'later', 'time', 'please', 'kindly', 'contact',
    ];

    /** Line types Verify OTP / session login may report; anything else logs as "other". */
    private const LINE_TYPES = ['prepaid', 'postpaid', 'hybrid'];

    /** Documented request body per operation; diagnostics report each field's shape, never its value. */
    private const REQUIRED_FIELDS = [
        'otp' => ['networkName', 'sender'],
        'verify' => ['networkName', 'sender', 'otp'],
        'session' => ['networkName', 'sender', 'sessionId'],
        'quota' => ['networkName', 'amount'],
        'convert' => ['networkName', 'sender', 'amount', 'reference', 'pin', 'sessionId'],
    ];

    public function __construct(private ProviderTransport $transport, private ProviderCallTrace $trace) {}

    public function key(): string
    {
        return 'airtime_to_cash_automation';
    }

    public function networks(): array
    {
        return ['mtn' => ['code' => 'MTN', 'min' => 50, 'max' => 10000],
            'airtel' => ['code' => 'AIRTEL', 'min' => 50, 'max' => 20000],
            'glo' => ['code' => 'GLO', 'min' => 50, 'max' => 1000],
            '9mobile' => ['code' => '9MOBILE', 'min' => 50, 'max' => 20000]];
    }

    public function capabilities(): array
    {
        return ['quota' => true, 'session' => true, 'lookup' => false];
    }

    public function credentialRequirements(bool $configured = false): array
    {
        return [['key' => 'token', 'label' => 'API token', 'secret' => true, 'configured' => $configured]];
    }

    public function healthCheck(): ProviderHealthResult
    {
        try {
            [$http, $body, $request] = $this->transport->post(
                $this->key(),
                '/api/v1/check/quota/availability',
                ['networkName' => 'MTN', 'amount' => 50],
                true,
                true,
            );
        } catch (ProviderRequestNotSent $e) {
            $this->trace->record('health', null, null, new ProviderResult('not_sent', reason: 'transport_preflight_rejected'), false);
            throw $e;
        }
        $this->trace->record('health', $http ?: null, $body['code'] ?? null, $this->normalize('quota', $http, $body), true, request: $request);
        $code = (string) ($body['code'] ?? '');
        if (in_array($http, [401, 403], true) || $code === '4030') {
            return new ProviderHealthResult(false, 'Authentication rejected by provider. Check the configured token.');
        }
        if ($http === 429 || $code === '4290') {
            return new ProviderHealthResult(false, 'Provider rate limit reached. Try the connection test later.');
        }
        if ($http === 200 && in_array($code, ['2000', '3000', '4000', '4010', '5030'], true)) {
            return new ProviderHealthResult(true, 'Authentication successful.');
        }

        return new ProviderHealthResult(false, 'Provider did not return a recognised health response.');
    }

    private function code(string $network): string
    {
        return $this->networks()[$network]['code'] ?? throw new \DomainException('Unsupported conversion network.');
    }

    /**
     * Every Automation endpoint is sent with the Bearer token. The pasted docs
     * list Generate/Verify OTP as unauthenticated, but production answers an
     * unauthenticated Generate OTP with HTTP 401 while the same token passes quota.
     */
    private function call(string $operation, string $path, #[\SensitiveParameter] array $payload): ProviderResult
    {
        $fields = $this->describePayload($operation, $payload);
        try {
            [$status, $body, $request] = $this->transport->post($this->key(), '/api/v1/'.$path, $payload);
        } catch (ProviderRequestNotSent) {
            $result = new ProviderResult('not_sent', reason: 'transport_preflight_rejected');
            $this->trace->record($operation, null, null, $result, false, request: $fields);

            return $result;
        }
        $result = $this->normalize($operation, $status, $body);
        $mode = $operation === 'convert' ? ['session_login_before_transfer' => $this->loginBeforeTransfer()] : [];
        $this->trace->record($operation, $status ?: null, $body['code'] ?? null, $result, true,
            request: [...$request, ...$fields, ...$this->describeResponse($operation, $payload, $body), ...$mode]);

        return $result;
    }

    /** Shape of the response fields that explain the next step, never their values. */
    private function describeResponse(string $operation, #[\SensitiveParameter] array $payload, #[\SensitiveParameter] array $body): array
    {
        if (! in_array($operation, ['verify', 'session', 'convert'], true)) {
            return [];
        }
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $session = self::describeValue($data['sessionId'] ?? null);
        if (isset($payload['sessionId']) && is_string($data['sessionId'] ?? null)) {
            // Whether the provider handed back the session it was given or issued a new one.
            $session['matches_request'] = hash_equals($payload['sessionId'], $data['sessionId']);
        }
        $described = ['response_session_id' => $session];
        if ($operation !== 'convert') {
            $type = is_string($data['type'] ?? null) ? strtolower(trim($data['type'])) : null;
            $described['response_airtime_balance'] = ['present' => array_key_exists('airtimeBalance', $data),
                'parsed' => ProviderResult::number($data['airtimeBalance'] ?? null) !== null];
            $described['response_line_type'] = $type === null ? null : (in_array($type, self::LINE_TYPES, true) ? $type : 'other');
        }

        return $described;
    }

    /**
     * Controlled hypothesis, off by default: call login/with/session/id immediately before
     * each transfer. The docs describe it as giving "access [to] airtime transfer functionality",
     * but production has never called it, so it stays opt-in until evidence settles it.
     */
    public function loginBeforeTransfer(): bool
    {
        return (bool) config('airtime_to_cash.providers.airtime_to_cash_automation.session_login_before_transfer', false);
    }

    /** Presence, type, length and format of each documented field; sensitive values never leave. */
    private function describePayload(string $operation, #[\SensitiveParameter] array $payload): array
    {
        $fields = [];
        foreach (self::REQUIRED_FIELDS[$operation] ?? [] as $name) {
            $value = $payload[$name] ?? null;
            $fields[$name] = self::describeValue($value) + match ($name) {
                'networkName' => ['value' => in_array($value, array_column($this->networks(), 'code'), true) ? $value : null],
                'sender' => ['valid_format' => is_string($value) && preg_match('/^0[789][0-9]{9}$/D', $value) === 1],
                'amount' => ['whole_number' => is_numeric($value) && (float) $value > 0 && floor((float) $value) === (float) $value],
                'reference' => ['matches_internal_reference' => $value !== null && $value === $this->trace->currentReference()],
                'pin', 'otp' => ['digits_only' => is_string($value) && ctype_digit($value)],
                'sessionId' => ['surrounding_whitespace' => is_string($value) && trim($value) !== $value],
                default => [],
            };
        }

        return ['payload_fields' => $fields,
            'missing_required_fields' => array_keys(array_filter($fields, fn ($field) => ! $field['present']))];
    }

    private static function describeValue(#[\SensitiveParameter] mixed $value): array
    {
        return ['present' => $value !== null && $value !== '', 'type' => get_debug_type($value)]
            + (is_string($value) ? ['length' => strlen($value)] : []);
    }

    public function normalize(string $operation, int $http, #[\SensitiveParameter] array $body): ProviderResult
    {
        if ($http === 0 || $http >= 500) {
            return new ProviderResult('unknown');
        }
        if ($http === 429) {
            return new ProviderResult('rate_limited');
        }
        $code = is_scalar($body['code'] ?? null) ? (string) $body['code'] : '';
        if (in_array($http, [401, 403], true) || ($http === 400 && $code === '4030')) {
            return new ProviderResult('auth_error');
        }
        if ($http < 200 || $http >= 300) {
            return new ProviderResult('failed');
        }
        // Quota documentation reuses 5030 for both available and unavailable.
        // Only the documented positive quota meaning permits OTP start; this
        // exception never proves that a transfer succeeded.
        $quota = $operation === 'quota' && $http === 200 && $code === '5030' ? $this->quotaSemantics($body) : null;
        if ($quota && $quota['outcome'] === 'recipients_available') {
            return new ProviderResult('success', semantic: $quota['outcome'], messageField: $quota['field'], messageTerms: $quota['terms']);
        }
        $state = match ($code) {
            '2000' => 'success', '3000' => 'failed', '4000' => 'pending',
            '4030' => 'auth_error', '4010' => 'session_expired', '4290' => 'rate_limited',
            '5030' => 'unavailable', default => 'unknown',
        };
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $message = is_string($body['message'] ?? null) ? strtolower($body['message']) : '';
        // Transfer 3000 covers several documented failures; classify them instead of a bare "failed".
        $transfer = $operation === 'convert' && $state === 'failed' ? $this->transferSemantics($body) : null;
        $reason = match (true) {
            $transfer !== null => $transfer['outcome'],
            $state === 'unavailable' && str_contains($message, 'unavailability of recipient') => 'recipient_unavailable',
            default => null,
        };
        $semantics = $quota ?? $transfer;
        $identifier = is_string($data['sessionId'] ?? null) && strlen($data['sessionId']) <= 2048 ? $data['sessionId'] : null;
        $amount = ProviderResult::number($data['amountConverted'] ?? null);
        if ($state === 'success' && in_array($operation, ['verify', 'session'], true) && ! $identifier) {
            $state = 'unknown';
        }
        if ($state === 'success' && $operation === 'convert' && ($amount === null || $amount <= 0)) {
            $state = 'unknown';
        }

        return new ProviderResult($state, $identifier, ProviderResult::number($data['airtimeBalance'] ?? null),
            ProviderResult::number($data['automationCharges'] ?? null), convertedAmount: $amount, reason: $reason,
            semantic: $semantics['outcome'] ?? null, messageField: $semantics['field'] ?? null, messageTerms: $semantics['terms'] ?? null,
            messageShape: $semantics['shape'] ?? null);
    }

    /**
     * Classifies a transfer 3000 by meaning, tolerating wording, case and punctuation.
     * Every outcome is a definitive failure: none can confirm delivery or credit a wallet.
     *
     * @return array{outcome: string, field: string, terms: list<string>}
     */
    private function transferSemantics(#[\SensitiveParameter] array $body): array
    {
        [$field, $words] = $this->messageWords($body);
        $has = fn (string ...$any) => array_intersect($words, $any) !== [];
        $negated = $has('not', 'no');
        // Order of vocabulary words only; every other word, including connecting words and any
        // token holding a digit, is "*", so the pattern can never reproduce the provider's sentence.
        $pattern = implode(' ', array_map(fn ($word) => in_array($word, self::TRANSFER_TERMS, true) ? $word : '*', array_slice($words, 0, 30)));

        return ['field' => $field, 'terms' => self::terms($words, self::TRANSFER_TERMS),
            'shape' => ['pattern' => $pattern, 'word_count' => count($words)], 'outcome' => match (true) {
                $has('pin') && ($has('invalid', 'incorrect', 'wrong', 'mismatch', 'rejected') || ($negated && $has('correct', 'valid'))) => 'invalid_pin',
                $has('insufficient') || ($has('balance', 'airtime', 'credit', 'fund', 'funds') && ($has('low') || ($negated && $has('enough', 'sufficient')))) => 'insufficient_balance',
                $has('session') && ($has('expired', 'invalid', 'unknown', 'login') || ($negated && $has('found', 'valid'))) => 'session_rejected',
                default => 'transfer_failed',
            }];
    }

    /**
     * The documented top-level `message` wins; `data.message` is read only when it is
     * absent, and a single-item list is unwrapped.
     *
     * @return array{0: string, 1: list<string>} the field used and its lowercase words
     */
    private function messageWords(#[\SensitiveParameter] array $body): array
    {
        [$field, $message] = match (true) {
            isset($body['message']) => ['message', $body['message']],
            is_array($body['data'] ?? null) && isset($body['data']['message']) => ['data.message', $body['data']['message']],
            default => ['none', null],
        };
        if (is_array($message) && array_is_list($message) && count($message) === 1) {
            $message = $message[0];
        }
        $words = is_string($message) ? preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY) : false;

        return [$field, is_array($words) ? $words : []];
    }

    /** @return list<string> vocabulary words present in the message, sorted */
    private static function terms(array $words, array $vocabulary): array
    {
        $terms = array_values(array_unique(array_intersect($words, $vocabulary)));
        sort($terms);

        return $terms;
    }

    /**
     * Documented quota success is {"code": 5030, "message": "Recipient(s) Available"};
     * any other 5030 means "Service/Recipient is unavailable". Case, whitespace and
     * punctuation are harmless differences, other wording is not proof of recipients.
     *
     * @return array{outcome: string, field: string, terms: list<string>}
     */
    private function quotaSemantics(#[\SensitiveParameter] array $body): array
    {
        [$field, $words] = $this->messageWords($body);

        return ['field' => $field, 'terms' => self::terms($words, self::QUOTA_TERMS), 'outcome' => match (true) {
            in_array(implode(' ', $words), ['recipient s available', 'recipients available'], true) => 'recipients_available',
            array_intersect($words, ['unavailable', 'unavailability', 'not', 'no']) !== [] => 'recipients_unavailable',
            default => 'unrecognised_5030',
        }];
    }

    public function requestOtp(string $network, string $phone): ProviderResult
    {
        return $this->call('otp', 'generate/otp', ['networkName' => $this->code($network), 'sender' => $phone]);
    }

    public function verifyOtp(string $network, string $phone, #[\SensitiveParameter] string $otp): ProviderResult
    {
        return $this->call('verify', 'verify/otp', ['networkName' => $this->code($network), 'sender' => $phone, 'otp' => $otp]);
    }

    public function checkSession(string $network, string $phone, #[\SensitiveParameter] string $identifier): ProviderResult
    {
        return $this->call('session', 'login/with/session/id', ['networkName' => $this->code($network), 'sender' => $phone, 'sessionId' => $identifier]);
    }

    public function checkQuota(string $network, float $amount): ProviderResult
    {
        return $this->call('quota', 'check/quota/availability', ['networkName' => $this->code($network), 'amount' => self::wireAmount($amount)]);
    }

    public function convert(string $network, string $phone, float $amount, string $reference,
        #[\SensitiveParameter] string $identifier, #[\SensitiveParameter] string $pin): ProviderResult
    {
        return $this->call('convert', 'transfer/airtime', ['networkName' => $this->code($network), 'sender' => $phone,
            'amount' => self::wireAmount($amount), 'reference' => $reference, 'sessionId' => $identifier, 'pin' => $pin]);
    }

    /**
     * The docs type `amount` as an integer. Whole amounts already encode as `500`, never
     * `500.0`, but sending an int makes that independent of float serialization.
     */
    private static function wireAmount(float $amount): int|float
    {
        return floor($amount) === $amount && abs($amount) < PHP_INT_MAX ? (int) $amount : $amount;
    }
}
