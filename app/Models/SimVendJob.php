<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimVendJob extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'transaction_reference',
        'user_id',
        'service',
        'network',
        'phone',
        'amount',
        'data_plan_id',
        'plan_snapshot',
        'status',
        'attempts',
        'max_attempts',
        'sim_device_id',
        'sim_id',
        'claimed_at',
        'lease_expires_at',
        'acked_at',
        'result',
        'failure_reason',
    ];

    protected $casts = [
        'amount' => 'float',
        'plan_snapshot' => 'array',
        'result' => 'array',
        'claimed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'acked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(SimDevice::class, 'sim_device_id');
    }

    public function sim(): BelongsTo
    {
        return $this->belongsTo(Sim::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_FAILED], true);
    }
}
