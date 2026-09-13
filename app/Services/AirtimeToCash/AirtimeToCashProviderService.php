<?php

namespace App\Services\AirtimeToCash;

use App\Models\AirtimeToCashRequest;
use App\Models\Network;
use App\Models\User;
use App\Services\AirtimeToCashAvailabilityService;
use App\Services\AirtimeToCashSettlementService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AirtimeToCashProviderService
{
    public function __construct(private AirtimeToCashProviderManager $manager, private AirtimeToCashAvailabilityService $availability) {}

    /** Automated availability: provider support and limits plus the Vendify rate, never manual destination settings. */
    public function inspect(?Network $network, ?float $amount = null): array
    {
        try {
            $limits = $network ? $this->manager->automatedLimits($this->availability::canonicalName($network->name)) : null;
        } catch (DomainException) {
            $limits = null;
        }

        return $this->availability->inspect($network, $amount, 'provider', $limits);
    }

    public function quote(int $networkId, float $amount): array
    {
        $network = $this->availability->resolve($networkId);
        $quote = $this->inspect($network, $amount);
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

            return $this->resume($existing->id, $userId)->setAttribute('resumed', true);
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
            $candidates = AirtimeToCashRequest::where('processing_mode', 'provider')
                ->where(fn ($query) => $query->where('active_session_key', $activeKey)
                    ->orWhere(fn ($query) => $query->where('network_id', $networkId)
                        ->where('sender_phone', $phone)->where('status', 'pending')))
                ->lockForUpdate()->get();
            foreach ($candidates as $active) {
                if (! $this->retainReservation($active)) {
                    continue;
                }
                if ((string) $active->user_id !== $userId) {
                    throw new DomainException('This SIM has an unresolved conversion. Contact support for help.');
                }
                return [$active, false];
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
            return $this->resume($request->id, $userId)->setAttribute('resumed', true);
        }
        // The durable reservation precedes ANY external call. A crash must not
        // cause a second start/OTP session when this idempotency key is replayed.
        try {
            if ($provider instanceof ChecksQuota) {
                $quota = app(ProviderCallTrace::class)->within($request->transaction_reference, fn () => $provider->checkQuota($name, $amount));
                if ($quota->state !== 'success') {
                    return $this->finishPreTransfer($request->id, 'created', $quota);
                }
            }
            $result = app(ProviderCallTrace::class)->within($request->transaction_reference, fn () => $provider->requestOtp($name, $phone));
        } catch (\Throwable) {
            $result = new ProviderResult('unknown');
        }

        return $this->finishPreTransfer($request->id, 'created', $result);
    }

    /** Called under a row lock. Never infer transfer failure from elapsed time. */
    private function retainReservation(AirtimeToCashRequest $request): bool
    {
        $terminal = in_array($request->status, ['failed', 'approved', 'rejected', 'cancelled'], true)
            || in_array($request->provider_status, ['failed', 'completed', 'expired', 'session_expired', 'rejected', 'cancelled'], true);
        if (! $terminal && ! $request->provider_confirmed_at && ! $request->payout_transaction_reference
            && $request->expires_at?->isPast()
            && (in_array($request->provider_status, ['created', 'awaiting_otp', 'verifying_otp', 'ready_to_transfer'], true)
                || (in_array($request->provider_status, ['setup_review', 'manual_review'], true) && (int) $request->provider_attempt_count === 0))) {
            // Only verification expires; no claim about an unknown transfer outcome.
            ProviderState::move($request, 'expired');
            $terminal = true;
        }
        if ($terminal) {
            $request->active_session_key = null;
            $request->provider_identifier = null;
            $request->save();
        }

        return ! $terminal;
    }

    public function active(string $userId): Collection
    {
        // Discovery is local only, including when new conversions are disabled.
        return DB::transaction(function () use ($userId) {
            return AirtimeToCashRequest::where('user_id', $userId)->where('processing_mode', 'provider')
                ->where(fn ($query) => $query->where('status', 'pending')->orWhereNotNull('active_session_key'))
                ->lockForUpdate()->get()
                ->filter(fn ($request) => $this->retainReservation($request))->values();
        });
    }

    public function resume(int $id, string $userId): AirtimeToCashRequest
    {
        $request = DB::transaction(function () use ($id, $userId) {
            $request = $this->owned($id, $userId);
            $this->retainReservation($request);

            return $request;
        });
        if ($request->status !== 'pending' || ! $request->active_session_key) {
            return $request;
        }
        if (in_array($request->provider_status, ['provider_confirmed', 'settlement_pending'], true)) {
            return $this->settleConfirmed($request);
        }
        $provider = $this->manager->bound($request);
        if ($request->provider_status === 'ready_to_transfer' && $provider instanceof ChecksSession && $request->provider_identifier) {
            // Read-only session validation. Unknown/auth/network errors are not expiry evidence.
            try {
                $result = app(ProviderCallTrace::class)->within($request->transaction_reference, fn () => $provider->checkSession($this->availability::canonicalName($request->network), $request->sender_phone, $request->provider_identifier));
            } catch (\Throwable) {
                $result = new ProviderResult('unknown');
            }
            if ($result->state === 'session_expired') {
                return DB::transaction(function () use ($id, $userId) {
                    $locked = $this->owned($id, $userId);
                    // A concurrent PIN submission may already have claimed the transfer.
                    if ($locked->status === 'pending' && $locked->provider_status === 'ready_to_transfer') {
                        ProviderState::move($locked, 'expired');
                        $locked->save();
                    }

                    return $locked;
                });
            }
        }
        if ($provider instanceof LooksUpTransactions
            && in_array($request->provider_status, ['processing', 'provider_pending', 'manual_review'], true)
            && ! $request->last_provider_check_at?->gt(now()->subMinute())) {
            return app(AirtimeToCashReconciliationService::class)->reconcile($id);
        }

        return $request->fresh();
    }

    private function owned(int $id, string $userId): AirtimeToCashRequest
    {
        return AirtimeToCashRequest::where('user_id', $userId)->where('processing_mode', 'provider')->lockForUpdate()->findOrFail($id);
    }

    public function verify(int $id, string $userId, #[\SensitiveParameter] string $otp): AirtimeToCashRequest
    {
        $request = DB::transaction(function () use ($id, $userId) {
            $request = $this->owned($id, $userId);
            if ($request->status !== 'pending' || $request->provider_status !== 'awaiting_otp') {
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
            return $request;
        }
        try {
            $result = app(ProviderCallTrace::class)->within($request->transaction_reference, fn () => $this->manager->bound($request)->verifyOtp($this->availability::canonicalName($request->network), $request->sender_phone, $otp));
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
            if ($request->status !== 'pending' || $request->provider_status !== $from) {
                return $request;
            }
            if ($result->state === 'success' && ($from === 'verifying_otp' || $result->skipOtp) && ! $result->identifier()) {
                $result = new ProviderResult('unknown');
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
                    'failed', 'rate_limited' => 'awaiting_otp', 'auth_error', 'not_sent' => 'failed', 'session_expired' => 'expired', default => 'setup_review',
                });
            } else {
                ProviderState::move($request, in_array($result->state, ['failed', 'auth_error', 'not_sent', 'unavailable'], true) ? 'failed' : 'setup_review');
            }
            $request->provider_message = $result->state;
            $request->save();

            return $request->fresh();
        });
    }

    public function convert(int $id, string $userId, #[\SensitiveParameter] string $pin): AirtimeToCashRequest
    {
        if (! preg_match('/^[0-9]{4}$/D', $pin)) {
            throw new DomainException('A four-digit SIM transfer PIN is required.');
        }
        $request = DB::transaction(function () use ($id, $userId) {
            $request = $this->owned($id, $userId);
            if ($request->status !== 'pending' || $request->provider_status !== 'ready_to_transfer') {
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
            $result = app(ProviderCallTrace::class)->within($request->transaction_reference, fn () => $this->manager->bound($request)->convert($this->availability::canonicalName($request->network), $request->sender_phone,
                (float) $request->amount, $request->provider_reference, $request->provider_identifier, $pin));
        } catch (ProviderRequestNotSent) {
            $result = new ProviderResult('not_sent', reason: 'transport_preflight_rejected');
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
            if ($request->status !== 'pending' || (int) $request->provider_attempt_count === 0 || ! in_array($request->provider_status, ['processing', 'provider_pending', 'manual_review'], true)) {
                return $request;
            }
            $state = $result->state;
            if ($state === 'success' && $result->convertedAmount !== null && $result->convertedAmount !== (float) $request->amount) {
                $state = 'unknown';
            }
            $retryableRejection = $request->provider_status === 'processing'
                && $state === 'failed' && in_array($result->reason, ['invalid_pin', 'low_balance'], true);
            $next = match (true) {
                $retryableRejection => 'ready_to_transfer',
                $state === 'not_sent' => 'setup_review',
                $state === 'auth_error' && $request->provider_status === 'processing' => 'failed',
                $state === 'unavailable' && $result->reason === 'recipient_unavailable' => 'failed',
                default => match ($state) {
                    'success' => 'provider_confirmed', 'failed' => 'failed', 'session_expired' => $request->provider_status === 'processing' ? 'session_expired' : 'provider_pending', default => 'provider_pending'
                },
            };
            if ($request->provider_status === 'manual_review' && $next === 'provider_pending') {
                return $request;
            }
            if ($state === 'not_sent') {
                $request->provider_attempt_count = max(0, (int) $request->provider_attempt_count - 1);
            }
            ProviderState::move($request, $next);
            $request->provider_message = $result->reason ?? $state;
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
        // Expired sessions release their SIM. Never reopen an old reference after release.
        $this->owned($id, $userId);
        throw new DomainException('Verification expired. Start a new conversion with a fresh quote.');
    }
}
