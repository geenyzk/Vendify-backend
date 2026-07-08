<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outbox for parent -&gt; child guidance (redirect_user, retry_transaction,
        // etc. — added in Phase 2). Phase 1 only ships the plumbing to list
        // and ack directives; nothing writes a real directive type yet.
        Schema::create('child_directives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_instance_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->enum('status', ['pending', 'delivered', 'failed'])->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_directives');
    }
};
