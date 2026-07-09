<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single message in an AiConversation, stored in OpenAI Chat Completions
 * shape (role/content/tool_calls/tool_call_id) so a conversation can be
 * replayed to the model without transformation. See AiMessage::toOpenAi().
 */
class AiMessage extends Model
{
    public const ROLE_SYSTEM = 'system';
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL = 'tool';

    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
    ];

    protected $casts = [
        'tool_calls' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /**
     * Shape this row the way the OpenAI Chat Completions API expects it in the
     * `messages` array. Null keys are dropped so, e.g., a plain user turn
     * doesn't carry a stray `tool_calls: null`.
     */
    public function toOpenAi(): array
    {
        $message = ['role' => $this->role];

        // A tool-only assistant turn legitimately has null content; the API
        // still wants the key present as null in that case, but for other
        // roles we only include content when set.
        if ($this->role === self::ROLE_ASSISTANT || $this->content !== null) {
            $message['content'] = $this->content;
        }

        if (!empty($this->tool_calls)) {
            $message['tool_calls'] = $this->tool_calls;
        }

        if ($this->tool_call_id !== null) {
            $message['tool_call_id'] = $this->tool_call_id;
        }

        return $message;
    }
}
