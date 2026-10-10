<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentPointProvisioning extends Model
{
    public const STATUS_KYC_REQUIRED = 'kyc_required';
    public const STATUS_FAILED = 'failed';
    public const STATUS_READY = 'ready';

    public function isRecovery(): bool
    {
        return $this->customer_created || $this->attempt_count > 0;
    }

    protected $fillable = [
        'user_id',
        'provider_id',
        'status',
        'last_error_category',
        'customer_created',
        'attempt_count',
        'last_attempt_at',
        'kyc_consent_at',
        'kyc_attempted_at',
    ];

    protected $casts = [
        'customer_created' => 'boolean',
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'kyc_consent_at' => 'datetime',
        'kyc_attempted_at' => 'datetime',
    ];
}
