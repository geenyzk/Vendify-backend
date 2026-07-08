<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildTransaction extends Model
{
    protected $fillable = [
        'child_instance_id',
        'child_customer_id',
        'external_id',
        'transaction_type',
        'amount',
        'status',
        'raw_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raw_payload' => 'array',
    ];

    public function childInstance(): BelongsTo
    {
        return $this->belongsTo(ChildInstance::class);
    }

    public function childCustomer(): BelongsTo
    {
        return $this->belongsTo(ChildCustomer::class);
    }
}
