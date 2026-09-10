<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AirtimeToCashRequest extends Model
{
    protected $fillable = [
        'user_id', 'network', 'amount', 'sender_phone', 'destination_number',
        'payout_amount', 'status', 'rejection_reason', 'proof_image',
        'reviewed_by', 'reviewed_at', 'transaction_reference', 'payout_transaction_reference',
        'processing_mode', 'network_id', 'provider', 'provider_reference', 'provider_identifier',
        'provider_status', 'provider_message', 'provider_started_at', 'provider_confirmed_at', 'expires_at',
        'last_provider_check_at', 'provider_attempt_count', 'otp_attempt_count', 'rate_snapshot',
        'provider_metadata', 'provider_cost', 'provider_fee', 'start_key', 'active_session_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payout_amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'provider_identifier' => 'encrypted', 'rate_snapshot' => 'array', 'provider_metadata' => 'array',
        'provider_started_at' => 'datetime', 'provider_confirmed_at' => 'datetime', 'expires_at' => 'datetime',
        'last_provider_check_at' => 'datetime', 'provider_attempt_count' => 'integer', 'otp_attempt_count' => 'integer',
        'provider_cost' => 'decimal:2', 'provider_fee' => 'decimal:2',
    ];

    protected $hidden = ['provider', 'provider_reference', 'provider_identifier', 'provider_message',
        'provider_cost', 'provider_fee', 'provider_metadata', 'rate_snapshot', 'start_key', 'active_session_key'];

    protected $appends = ['lifecycle'];

    /** One public lifecycle projection shared by customer and administrative APIs. */
    public function getLifecycleAttribute(): array
    {
        $automated = $this->processing_mode === 'provider';
        $state = $this->provider_status;
        $attempted = (int) $this->provider_attempt_count > 0;
        if ($state === 'manual_review' && ! $attempted) {
            $state = 'setup_review';
        }
        $terminal = in_array($this->status, ['approved', 'failed', 'rejected', 'cancelled'], true)
            || in_array($state, ['failed', 'completed', 'expired', 'session_expired'], true);
        $label = match (true) {
            $this->status === 'approved' => 'Successful',
            $this->status === 'failed' || $state === 'failed' => 'Failed',
            $this->status === 'rejected' => 'Rejected',
            $this->status === 'cancelled' => 'Cancelled',
            ! $automated => 'Awaiting admin review',
            in_array($state, ['expired', 'session_expired'], true) => 'Verification expired',
            $state === 'ready_to_transfer' => 'Awaiting transfer PIN',
            $state === 'setup_review' => 'Needs attention',
            in_array($state, ['provider_pending', 'manual_review'], true) => 'Needs confirmation',
            in_array($state, ['processing', 'provider_confirmed', 'settlement_pending'], true) => 'Processing',
            default => 'Awaiting verification',
        };
        return [
            'state' => $state,
            'label' => $label,
            'terminal' => $terminal,
            'resumable' => $automated && ! $terminal,
            'transfer_submitted' => $attempted,
            'can_change_method' => $terminal || ! $attempted,
            'stage' => $this->status === 'approved' ? 3 : ($state === 'ready_to_transfer' ? 1 : ($attempted ? 2 : 0)),
            'can_approve' => ! $automated && $this->status === 'pending',
            'can_reconcile' => $automated && ! $terminal && $attempted && $this->provider === '2fast'
                && in_array($state, ['processing', 'provider_pending', 'manual_review'], true),
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $request) {
            if (! $request->wasChanged(['provider_status', 'status']) && ! $request->wasRecentlyCreated) {
                return;
            }
            try {
                \Illuminate\Support\Facades\Log::info('airtime_to_cash.lifecycle', [
                    'internal_reference' => $request->transaction_reference,
                    'provider' => $request->provider,
                    'operation' => 'lifecycle_transition',
                    'from' => $request->getRawOriginal('provider_status'),
                    'to' => $request->provider_status,
                    'status' => $request->status,
                    'transfer_submitted' => (int) $request->provider_attempt_count > 0,
                ]);
            } catch (\Throwable) {
                // Observability cannot change a settlement outcome.
            }
        });
        static::saving(function (self $request) {
            if ($request->processing_mode === 'provider') {
                if ($request->exists && ($request->getRawOriginal('status') === 'failed'
                    || $request->getRawOriginal('provider_status') === 'failed')
                    && $request->provider_status !== 'failed') {
                    throw new \DomainException('A failed conversion cannot be reopened.');
                }
                if ($request->provider_status === 'failed') {
                    if ($request->status === 'approved' || $request->provider_confirmed_at
                        || $request->payout_transaction_reference || ($request->exists && $request->payoutTransaction()->exists())) {
                        throw new \DomainException('A confirmed or paid conversion cannot be marked failed.');
                    }
                    $request->status = 'failed';
                    $request->active_session_key = null;
                    $request->provider_identifier = null;
                } elseif ($request->status === 'failed') {
                    throw new \DomainException('A failed conversion requires a terminal provider failure.');
                }
            }
            foreach (['pin', 'otp', 'transfer_pin', 'token'] as $secret) {
                if (array_key_exists($secret, $request->getAttributes())) {
                    throw new \DomainException('Sensitive conversion data cannot be persisted.');
                }
            }
            if ($request->exists && $request->isDirty('processing_mode')) {
                throw new \DomainException('Conversion mode is immutable.');
            }
            if ($request->exists && $request->getOriginal('processing_mode') === 'provider') {
                foreach (['user_id', 'network_id', 'network', 'sender_phone', 'amount', 'payout_amount', 'rate_snapshot',
                    'provider', 'provider_reference', 'transaction_reference', 'start_key'] as $field) {
                    if ($request->isDirty($field)) {
                        throw new \DomainException('Conversion identity and pricing are immutable.');
                    }
                }
            }
            foreach ($request->provider_metadata ?? [] as $key => $value) {
                if ($key !== 'airtime_balance' || ! is_numeric($value)) {
                    throw new \DomainException('Unsupported conversion metadata.');
                }
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function payoutTransaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'airtime_to_cash_request_id');
    }
}
