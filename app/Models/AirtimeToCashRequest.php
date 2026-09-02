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
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payout_amount' => 'decimal:2',
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

    public function payoutTransaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'airtime_to_cash_request_id');
    }
}
