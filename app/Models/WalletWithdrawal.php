<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletWithdrawal extends Model
{
    protected $fillable = [
        'user_id', 'amount', 'bank_code', 'bank_name', 'account_number', 'account_name',
        'status', 'rejection_reason', 'gateway_reference', 'transaction_reference',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
