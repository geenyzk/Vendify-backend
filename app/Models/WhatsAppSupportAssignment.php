<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppSupportAssignment extends Model
{
    protected $fillable = [
        'agent_id', 'customer_id', 'ticket_id', 'transaction_id',
        'phone_number', 'source', 'assigned_at',
    ];

    protected $casts = ['assigned_at' => 'datetime'];

    public function agent(): BelongsTo { return $this->belongsTo(WhatsAppSupportAgent::class, 'agent_id')->withTrashed(); }
    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_id'); }
    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'ticket_id'); }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class, 'transaction_id'); }
}
