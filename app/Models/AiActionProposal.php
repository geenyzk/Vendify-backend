<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mutating action the AI wants to take, held for human approval. This is the
 * gate that keeps the assistant "read + propose" rather than "read + act": the
 * model records a proposal here in `pending`, and only an explicit admin
 * approval (re-checked against the underlying permission) runs the handler.
 * Also the audit log — who decided, when, and the result. See
 * App\Services\AiManager\AiManagerService::approve().
 */
class AiActionProposal extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'ai_conversation_id',
        'ai_message_id',
        'tool',
        'permission',
        'arguments',
        'summary',
        'status',
        'result',
        'error',
        'decided_by',
        'decided_at',
        'executed_at',
    ];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'decided_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
