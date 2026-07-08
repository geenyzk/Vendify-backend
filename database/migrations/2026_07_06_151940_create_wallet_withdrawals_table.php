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
        // Schema drift (dump imports): the table may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasTable('wallet_withdrawals')) {
            return;
        }

        Schema::create('wallet_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->decimal('amount', 15, 2);
            $table->string('bank_code');
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('account_name');
            $table->enum('status', ['pending', 'completed', 'failed', 'rejected'])->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->string('transaction_reference')->unique();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_withdrawals');
    }
};
