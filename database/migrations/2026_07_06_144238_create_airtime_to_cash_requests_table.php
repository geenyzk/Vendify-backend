<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('airtime_to_cash_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->string('network');
            $table->decimal('amount', 15, 2);
            $table->string('sender_phone');
            $table->string('destination_number');
            $table->decimal('payout_amount', 15, 2);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->string('proof_image')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('transaction_reference')->unique();
            $table->string('payout_transaction_reference')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airtime_to_cash_requests');
    }
};
