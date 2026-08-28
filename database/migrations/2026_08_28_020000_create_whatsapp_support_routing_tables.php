<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_support_agents', function (Blueprint $table) {
            $table->id();
            $table->string('display_name', 120);
            $table->string('phone_number', 16)->unique();
            $table->boolean('enabled')->default(true);
            $table->string('availability', 20)->default('available');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('last_assigned_at')->nullable();
            $table->unsignedBigInteger('assignment_count')->default(0);
            $table->unsignedBigInteger('linked_user_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('department', 80)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('linked_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['enabled', 'availability', 'last_assigned_at']);
        });

        Schema::create('whatsapp_support_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->nullable()->constrained('whatsapp_support_agents')->nullOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->foreignId('ticket_id')->nullable()->constrained('support_tickets')->nullOnDelete();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('phone_number', 16);
            $table->string('source', 30)->default('generic');
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('transaction_id')->references('id')->on('transactions')->nullOnDelete();
            $table->index(['ticket_id', 'assigned_at']);
            $table->index(['customer_id', 'assigned_at']);
            $table->index(['agent_id', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_support_assignments');
        Schema::dropIfExists('whatsapp_support_agents');
    }
};
