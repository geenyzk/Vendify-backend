<?php

namespace App\Services\Electricity;

use App\HttpResponse;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ElectricitySandboxProvider
{
    use HttpResponse;

    private const METERS = [
        '1111111111111' => ['name' => 'VENDIFY TEST CUSTOMER', 'type' => 'prepaid'],
        '2222222222222' => ['name' => 'VENDIFY POSTPAID TEST', 'type' => 'postpaid'],
    ];

    public function __construct(private readonly ElectricitySandboxAccess $access) {}

    public function enabledFor(?User $user): bool
    {
        return $this->access->allowedFor($user);
    }

    public function verify(User $user, string $meter, string $meterType): JsonResponse
    {
        $this->assertAllowed($user);
        Log::info('[ELECTRICITY SANDBOX] Meter verification', ['meter_number' => $meter]);

        if ($meter === '3000000000000') return $this->fail([], 'Meter verification timed out.', 504, 'provider_timeout');
        if ($meter === '4000000000000') return $this->fail([], 'This meter could not be verified.', 422, 'verification_failed');
        if ($meter === '5000000000000') return $this->fail([], 'Electricity provider is temporarily unavailable.', 503, 'provider_unavailable');

        $fixture = self::METERS[$meter] ?? null;
        if (! $fixture || $fixture['type'] !== $meterType) {
            return $this->fail([], 'This meter number is not available in electricity sandbox mode.', 422, 'unsupported_sandbox_meter');
        }

        return $this->success([
            'name' => $fixture['name'],
            'customer_name' => $fixture['name'],
            'address' => 'TEST ADDRESS, IKEJA, LAGOS',
            'customer_address' => 'TEST ADDRESS, IKEJA, LAGOS',
            'meter_number' => $meter,
            'meter_type' => $fixture['type'],
            'distribution_company' => 'ikeja-electric',
            'sandbox' => true,
        ], 'Electricity sandbox verification successful.');
    }

    public function purchase(User $user, array $payload): JsonResponse
    {
        $this->assertAllowed($user);
        $meter = (string) $payload['meter_number'];
        $type = (string) $payload['meter_type'];
        if ($meter === '3000000000000') return $this->fail([], 'Electricity purchase timed out.', 504, 'provider_timeout');
        if ($meter === '4000000000000') return $this->fail([], 'This meter could not be verified.', 422, 'verification_failed');
        if ($meter === '5000000000000') return $this->fail([], 'Electricity provider is temporarily unavailable.', 503, 'provider_unavailable');
        $fixture = self::METERS[$meter] ?? null;
        if (! $fixture || $fixture['type'] !== $type) {
            return $this->fail([], 'This meter number is not available in electricity sandbox mode.', 422, 'unsupported_sandbox_meter');
        }

        $token = $type === 'prepaid' ? $this->token((string) $payload['tx_ref']) : null;
        $transaction = Transaction::create([
            'user_id' => $user->id,
            'transaction_type' => 'electric_bill',
            'provider' => 'electricity_sandbox',
            'account_or_phone' => $meter,
            'receiver' => $meter,
            'amount' => (float) $payload['amount'],
            'cost' => 0,
            'service_fee' => 0,
            'discount_amount' => 0,
            'status' => 'success',
            'transaction_reference' => $payload['tx_ref'],
            'payment_reference' => 'sandbox_'.$payload['tx_ref'],
            'balance_before' => $user->wallet_balance,
            'balance_after' => $user->wallet_balance,
            'plan_type' => $type,
            'token' => $token,
            'platform' => 'sandbox',
            'response_message' => 'Sandbox electricity purchase successful.',
            'completed_at' => now(),
            'is_sandbox' => true,
            'raw_payload' => ['sandbox' => true, 'meter_type' => $type, 'disco' => $payload['disco']],
        ]);

        Log::info('[ELECTRICITY SANDBOX] Purchase simulated', ['transaction_reference' => $payload['tx_ref']]);
        $user->notify(new AppNotification('transaction_success', 'Sandbox electricity purchase successful', "Test reference: {$payload['tx_ref']}"));

        $data = $transaction->toArray();
        $data['sandbox'] = true;
        $data['meter_number'] = $meter;
        $data['discount_applied'] = ['discount_amount' => 0, 'original_amount' => (float) $payload['amount'], 'final_amount' => (float) $payload['amount'], 'promotion_id' => null];
        return $this->success($data, 'Sandbox electricity purchase successful.');
    }

    private function token(string $reference): string
    {
        $digits = substr(preg_replace('/\D/', '', hash('sha256', $reference)) ?: '12345678901234567890', 0, 20);
        return implode(' ', str_split(str_pad($digits, 20, '0'), 4));
    }

    private function assertAllowed(User $user): void
    {
        if (! $this->enabledFor($user)) {
            throw new \LogicException('Electricity sandbox access denied.');
        }
    }
}
