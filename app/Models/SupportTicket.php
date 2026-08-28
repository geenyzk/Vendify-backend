<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    public const STATUSES = ['open', 'in_review', 'awaiting_customer', 'resolved', 'closed'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const CATEGORIES = [
        'transaction', 'wallet_funding', 'account_access', 'kyc', 'airtime_data',
        'electricity', 'cable_tv', 'exam_pin', 'other',
    ];
    public const ISSUE_TYPES = [
        'failed', 'pending', 'not_received', 'duplicate_charge', 'incorrect_amount',
        'refund', 'funding_not_received', 'locked_out', 'verification', 'service_issue', 'other',
    ];
    public const STATUS_TRANSITIONS = [
        'open' => ['in_review', 'awaiting_customer', 'resolved', 'closed'],
        'in_review' => ['open', 'awaiting_customer', 'resolved', 'closed'],
        'awaiting_customer' => ['open', 'in_review', 'resolved', 'closed'],
        'resolved' => ['open', 'in_review', 'closed'],
        'closed' => [],
    ];

    protected $fillable = [
        'user_id', 'transaction_id', 'category', 'issue_type', 'subject',
        'description', 'status', 'priority', 'assigned_to', 'resolved_at', 'closed_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            if (!$ticket->reference) {
                $ticket->reference = self::generateReference();
            }
        });
    }

    public static function generateReference(): string
    {
        do {
            $reference = 'VEN-' . strtoupper(Str::random(7));
        } while (self::where('reference', $reference)->exists());

        return $reference;
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function messages(): HasMany { return $this->hasMany(SupportTicketMessage::class); }
    public function notes(): HasMany { return $this->hasMany(SupportTicketNote::class); }
    public function whatsappAssignments(): HasMany { return $this->hasMany(WhatsAppSupportAssignment::class, 'ticket_id'); }
}
