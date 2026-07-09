<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transcript of a conversation, stored in OpenAI Chat Completions shape so
 * a turn can be replayed to the model verbatim:
 *  - role: system | user | assistant | tool
 *  - content: the text (nullable for an assistant turn that only calls tools)
 *  - tool_calls: JSON array the assistant emitted (function calls it wants run)
 *  - tool_call_id: set on a role=tool message, linking a tool result back to
 *    the assistant call that requested it
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->timestamps();

            $table->index(['ai_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
