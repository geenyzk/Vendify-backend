<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "credit this child customer" instruction, produced when the parent
 * receives funding for a child virtual account. The child pulls and acks these.
 */
class ChildCreditEvent extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CREDITED = 'credited';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'child_instance_id', 'child_virtual_account_id', 'external_customer_id',
        'amount', 'gross_amount', 'fee', 'provider', 'reference',
        'status', 'delivered_at', 'acked_at', 'result_note', 'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'delivered_at' => 'datetime',
        'acked_at' => 'datetime',
        'meta' => 'array',
    ];

    public function childInstance(): BelongsTo
    {
        return $this->belongsTo(ChildInstance::class);
    }

    public function virtualAccount(): BelongsTo
    {
        return $this->belongsTo(ChildVirtualAccount::class, 'child_virtual_account_id');
    }
}
