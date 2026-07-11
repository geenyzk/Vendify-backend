<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbox of vends waiting for a SIM device. Lifecycle:
     * pending -> claimed (leased to one device) -> success | failed.
     * The customer's transaction sits at 'pending' with funds reserved
     * until the ack (or the expiry sweeper) settles it.
     */
    public function up(): void
    {
        Schema::create('sim_vend_jobs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            // Same reference as the customer's transactions row — the ack
            // settles that transaction through the vendor webhook logic.
            $table->string('transaction_reference')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('service');
            $table->string('network');
            $table->string('phone');
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('data_plan_id')->nullable();
            $table->json('plan_snapshot')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(2);
            $table->unsignedBigInteger('sim_device_id')->nullable();
            $table->unsignedBigInteger('sim_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->json('result')->nullable();
            $table->string('failure_reason')->nullable();

            $table->index(['status', 'network']);
            $table->index('lease_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sim_vend_jobs');
    }
};
