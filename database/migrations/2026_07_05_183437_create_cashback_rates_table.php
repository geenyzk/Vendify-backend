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
        Schema::create('cashback_rates', function (Blueprint $table) {
            $table->id();
            // Matches Transaction::transaction_type values (e.g.
            // 'airtime_recharge', 'data_subscription') — this is the key
            // TransactionService looks up at credit time, not a Discount
            // "type". A flat, per-service-type rate that applies platform
            // wide, replacing the old per-role discount pricing.
            $table->string('service_type')->unique();
            $table->decimal('percentage', 5, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashback_rates');
    }
};
