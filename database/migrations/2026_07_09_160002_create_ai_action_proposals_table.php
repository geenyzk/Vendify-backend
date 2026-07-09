<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mutating action the AI wants to take (refund a transaction, fund a wallet,
 * toggle a service, ...). The assistant NEVER executes these directly — it
 * records a proposal here in `pending`, and an admin must explicitly approve it
 * before the underlying handler runs. This table doubles as the audit log:
 * every AI-driven change carries who approved it, when it executed, and its
 * result / error.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_action_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            // The assistant message whose tool_call spawned this proposal.
            $table->foreignId('ai_message_id')->nullable()->constrained()->nullOnDelete();

            $table->string('tool');                 // registry tool name, e.g. refund_transaction
            $table->string('permission')->nullable(); // permission slug re-checked at approval time
            $table->json('arguments');              // validated args the handler will run with
            $table->text('summary')->nullable();    // human-readable "what this will do"

            // pending -> approved (executing) -> executed | failed ; or rejected
            $table->string('status', 20)->default('pending');
            $table->json('result')->nullable();     // handler return payload on success
            $table->text('error')->nullable();      // failure reason if execution threw

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();

            $table->timestamps();

            $table->index(['ai_conversation_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_action_proposals');
    }
};
