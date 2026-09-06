<?php

namespace App\Services\AirtimeToCash;

use App\Models\AirtimeToCashRequest;
use App\Models\User;
use App\Services\AirtimeToCashAvailabilityService;
use App\Services\AirtimeToCashSettlementService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AirtimeToCashProviderService
{
    public function __construct(private AirtimeToCashProviderManager $manager, private AirtimeToCashAvailabilityService $availability) {}

    public function quote(int $networkId, float $amount): array
    {
        $network = $this->availability->resolve($networkId);
        $quote = $this->availability->inspect($network, $amount, 'provider');
        if (! $quote['available']) {
            throw new DomainException($quote['reason']);
        }
        $this->manager->select($this->availability::canonicalName($network->name), $amount);
        unset($quote['destination_number']);

        return $quote;
    }

    public function start(string $userId, int $networkId, float $amount, float $payout, string $phone, string $key): AirtimeToCashRequest
    {
        $startKey = hash('sha256', $userId.':'.$key);
        $existing = AirtimeToCashRequest::where('user_id', $userId)->where('start_key', $startKey)->first();
        if ($existing) {
            if ((int) $existing->network_id !== $networkId || (float) $existing->amount !== $amount || $existing->sender_phone !== $phone
                || (float) $existing->payout_amount !== $payout) {
                throw new DomainException('This start reference belongs to a different conversion.');
            }

            return $existing;
        }
        $quote = $this->quote($networkId, $amount);
        if ($quote['payout_amount'] !== $payout) {
            throw new DomainException('The rate changed. Review a fresh quote before starting.');
        }
        $name = $this->availability::canonicalName($quote['network']);
        $provider = $this->manager->select($name, $amount);
        $record = DB::transaction(function () use ($userId, $networkId, $amount, $payout, $phone, $startKey, $quote, $provider, $name) {
            User::lockForUpdate()->findOrFail($userId);
            $existing = AirtimeToCashRequest::where('user_id', $userId)->where('start_key', $startKey)->first();
            if ($existing) {
                return [$existing, false];
            }
            $activeKey = hash('sha256', $name.':'.$phone);
            if (AirtimeToCashRequest::where('active_session_key', $activeKey)->exists()) {
                throw new DomainException('An active conversion already exists for this SIM. Resume it before starting another.');
            }
            $reference = 'ATC-'.Str::uuid();

            return [AirtimeToCashRequest::create([
                'user_id' => $userId, 'network_id' => $networkId, 'network' => $quote['network'],
                'sender_phone' => $phone, 'amount' => $amount, 'payout_amount' => $payout,
                'destination_number' => '', 'status' => 'pending', 'processing_mode' => 'provider',
                'provider' => $provider->key(), 'provider_reference' => $reference, 'transaction_reference' => $reference,
                'provider_status' => 'created', 'start_key' => $startKey, 'active_session_key' => $activeKey,
                'provider_started_at' => now(), 'expires_at' => now()->addMinutes(config('airtime_to_cash.session_minutes')),
                'rate_snapshot' => ['rate_id' => $quote['rate_id'], 'type' => $quote['rate_type'], 'value' => $quote['rate_value']],
            ]), true];
        });
        [$request, $created] = $record;
        if (! $created) {
            return $request;
        }
        // The durable reservation precedes ANY external call. A crash must not
        // cause a second start/OTP session when this idempotency key is replayed.
        try {
            if ($provider instanceof ChecksQuota) {
                $quota = $provider->checkQuota($name, $amount);
                if ($quota->state !== 'success') {
                    return $this->finishPreTransfer($request->id, 'created', new ProviderResult('failed'));
                }
            }
            $result = $provider->requestOtp($name, $phone);
        } catch (\Throwable) {
            $result = new ProviderResult('unknown');
        }

        return $this->finishPreTransfer($request->id, 'created', $result);
    }

    private function owned(int $id, string $userId): AirtimeToCashRequest
    {
        return AirtimeToCashRequest::where('user_id', $userId)->where('processing_mode', 'provider')->lockForUpdate()->findOrFail($id);
    }

    public function verify(int $id, string $userId, #[\SensitiveParameter] string $otp): AirtimeToCashRequest
    {
        $request = DB::transaction(function () use ($id, $userId) {
            $request = $this->owned($id, $userId);
            if ($request->provider_status !== 'awaiting_otp') {
                throw new DomainException('This conversion is not awaiting an OTP.');
            }
            if ($request->expires_at?->isPast()) {
                ProviderState::move($request, 'expired');
                $request->save();

                return $request;
            }
            if ($request->otp_attempt_count >= 5) {
                throw new DomainException('OTP attempt limit reached.');
            }
            ProviderState::move($request, 'verifying_otp');
            $request->otp_attempt_count++;
            $request->save();

            return $request;
        });
        if ($request->provider_status === 'expired') {
            throw new DomainException('Verification expired. Restart verification.');
        }
        try {
            $result = $this->manager->bound($request)->verifyOtp($this->availability::canonicalName($request->network), $request->sender_phone, $otp);
        } catch (\Throwable) {
            $result = new ProviderResult('unknown');
        } finally {
            unset($otp);
        }

        return $this->finishPreTransfer($id, 'verifying_otp', $result);
    }

    private function finishPreTransfer(int $id, string $from, ProviderResult $result): AirtimeToCashRequest
    {
        return DB::transaction(function () use ($id, $from, $result) {
            $request = AirtimeToCashRequest::lockForUpdate()->findOrFail($id);
            if ($request->provider_status !== $from) {
                return $request;
            }
            if ($result->state === 'success') {
                $ready = $from === 'verifying_otp' || $result->skipOtp;
                ProviderState::move($request, $ready ? 'ready_to_transfer' : 'awaiting_otp');
                if ($ready) {
                    $request->provider_identifier = $result->identifier();
                }
                if ($result->balance !== null) {
                    $request->provider_metadata = ['airtime_balance' => $result->balance];
                }
            } elseif ($from === 'verifying_otp') {
                ProviderState::move($request, match ($result->state) {
                    'failed', 'rate_limited' => 'awaiting_otp', 'session_expired' => 'expired', default => 'manual_review',
                });
            } else {
                ProviderState::move($request, in_array($result->state, ['failed', 'auth_error', 'unavailable', 'rate_limited'], true) ? 'failed' : 'manual_review');
            }
            $request->provider_message = $result->state;
            $request->save();

            return $request->fresh();
        });
    }

    public function convert(int $id, string $userId, #[\SensitiveParameter] string $pin): AirtimeToCashRequest
    {
        $request = DB::transaction(function () use ($id, $userId) {
            $request = $this->owned($id, $userId);
            if ($request->provider_status !== 'ready_to_transfer') {
                return $request;
            } // Duplicate submit never calls telecom twice.
            if ($request->expires_at?->isPast()) {
                ProviderState::move($request, 'expired');
                $request->save();

                return $request;
            }
            if (! $request->provider_identifier) {
                throw new DomainException('SIM verification is required.');
            }
            if (isset($request->provider_metadata['airtime_balance']) && $request->provider_metadata['airtime_balance'] < (float) $request->amount) {
                throw new DomainException('The verified SIM airtime balance is too low.');
            }
            ProviderState::move($request, 'processing');
            $request->provider_attempt_count++;
            $request->last_provider_check_at = now();
            $request->save();
            $request->setAttribute('_claimed', true);

            return $request;
        });
        $claimed = $request->getAttribute('_claimed');
        $request->offsetUnset('_claimed');
        if (! $claimed) {
            return $request;
        }
        try {
            $result = $this->manager->bound($request)->convert($this->availability::canonicalName($request->network), $request->sender_phone,
                (float) $request->amount, $request->provider_reference, $request->provider_identifier, $pin);
        } catch (\Throwable) {
            $result = new ProviderResult('unknown');
        } finally {
            unset($pin);
        }

        return $this->recordResult($id, $result);
    }

    public function recordResult(int $id, ProviderResult $result): AirtimeToCashRequest
    {
        $request = DB::transaction(function () use ($id, $result) {
            $request = AirtimeToCashRequest::where('processing_mode', 'provider')->lockForUpdate()->findOrFail($id);
            if (! in_array($request->provider_status, ['processing', 'provider_pending', 'manual_review'], true)) {
                return $request;
            }
            $state = $result->state;
            if ($state === 'success' && $result->convertedAmount !== null && $result->convertedAmount !== (float) $request->amount) {
                $state = 'unknown';
            }
            $next = match ($state) {
                'success' => 'provider_confirmed', 'failed' => 'failed', 'session_expired' => 'session_expired', default => 'provider_pending'
            };
            if ($request->provider_status === 'manual_review' && $next === 'provider_pending') {
                return $request;
            }
            ProviderState::move($request, $next);
            $request->provider_message = $state;
            if ($next === 'provider_confirmed') {
                $request->provider_confirmed_at = now();
                $request->provider_identifier = null;
                if ($result->fee !== null) {
                    $request->provider_fee = $result->fee;
                }
                if ($result->cost !== null) {
                    $request->provider_cost = $result->cost;
                }
            }
            $request->save();

            return $request;
        });

        return $this->settleConfirmed($request);
    }

    public function settleConfirmed(AirtimeToCashRequest $request): AirtimeToCashRequest
    {
        if (! in_array($request->provider_status, ['provider_confirmed', 'settlement_pending'], true)) {
            return $request;
        }
        try {
            return app(AirtimeToCashSettlementService::class)->settle($request->id);
        } catch (\Throwable) {
            // Keep evidence if wallet storage is temporarily unavailable. No raw exception may escape.
            DB::transaction(function () use ($request) {
                $locked = AirtimeToCashRequest::lockForUpdate()->findOrFail($request->id);
                if ($locked->provider_status === 'provider_confirmed') {
                    ProviderState::move($locked, 'settlement_pending');
                    $locked->save();
                }
            });

            return $request->fresh();
        }
    }

    public function restartOtp(int $id, string $userId): AirtimeToCashRequest
    {
        $request = DB::transaction(function () use ($id, $userId) {
            $request = $this->owned($id, $userId);
            if (! in_array($request->provider_status, ['expired', 'session_expired'], true)) {
                throw new DomainException('This conversion cannot restart verification safely.');
            }
            ProviderState::move($request, 'created');
            $request->expires_at = now()->addMinutes(config('airtime_to_cash.session_minutes'));
            $request->provider_identifier = null;
            $request->save();

            return $request;
        });
        try {
            $result = $this->manager->bound($request)->requestOtp($this->availability::canonicalName($request->network), $request->sender_phone);
        } catch (\Throwable) {
            $result = new ProviderResult('unknown');
        }

        return $this->finishPreTransfer($id, 'created', $result);
    }
}
