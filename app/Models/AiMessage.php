<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single message in an AiConversation. The table still stores the local
 * transcript/audit shape, while toOpenAi() converts it to Responses API input.
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
     * Shape this row as one or more OpenAI Responses API input items.
     *
     * @return array<int, array>
     */
    public function toOpenAi(): array
    {
        if ($this->role === self::ROLE_TOOL) {
            return [[
                'type' => 'function_call_output',
                'call_id' => $this->tool_call_id,
                'output' => $this->content ?? '{}',
            ]];
        }

        $items = [];

        if ($this->content !== null) {
            $items[] = [
                'role' => $this->role === self::ROLE_SYSTEM ? self::ROLE_USER : $this->role,
                'content' => $this->role === self::ROLE_SYSTEM
                    ? '[System note] ' . $this->content
                    : $this->content,
            ];
        }

        if (!empty($this->tool_calls)) {
            foreach ($this->tool_calls as $call) {
                $items[] = [
                    'type' => 'function_call',
                    'call_id' => $call['call_id'] ?? $call['id'] ?? null,
                    'name' => $call['name'] ?? $call['function']['name'] ?? '',
                    'arguments' => is_string($call['arguments'] ?? null)
                        ? $call['arguments']
                        : json_encode($call['arguments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        return $items;
    }
}
