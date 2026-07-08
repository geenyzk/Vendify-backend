<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outbound log of one-off emails a parent admin sends to a child
        // affiliate's customer (ChildCustomerContactController). One row per
        // send — this is what makes the contact modal read as a conversation
        // history instead of fire-and-forget. Replies arrive in the admin's
        // normal inbox; only our half is recorded here.
        Schema::create('child_customer_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_customer_messages');
    }
};
