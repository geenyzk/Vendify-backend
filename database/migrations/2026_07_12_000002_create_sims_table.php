<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per physical SIM in a device slot. Balances are what the
     * device last reported (heartbeat/ack) — routing skips SIMs whose
     * stock can't cover a vend, and admins get low-balance alerts.
     */
    public function up(): void
    {
        Schema::create('sims', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->foreignId('sim_device_id')->constrained('sim_devices')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_index');
            $table->string('network');
            $table->string('phone_number')->nullable();
            $table->boolean('supports_airtime')->default(true);
            // Data gifting needs an eligible bundle on the SIM, so it stays
            // off until the admin confirms the SIM can share data.
            $table->boolean('supports_data')->default(false);
            $table->decimal('airtime_balance', 12, 2)->default(0);
            $table->decimal('data_balance_mb', 12, 2)->default(0);
            $table->decimal('airtime_low_threshold', 12, 2)->default(1000);
            $table->decimal('data_low_threshold_mb', 12, 2)->default(1024);
            $table->boolean('enabled')->default(true);
            $table->timestamp('balance_reported_at')->nullable();
            $table->string('notes')->nullable();

            $table->unique(['sim_device_id', 'slot_index']);
            $table->index(['network', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sims');
    }
};
