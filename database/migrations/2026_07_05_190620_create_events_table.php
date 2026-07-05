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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // What's being measured, and against what threshold — see
            // EventService::metricValue for exactly how each is computed
            // from real Transaction/User data (nothing is double-tracked).
            $table->enum('metric', [
                'referral_count',
                'transaction_volume',
                'transaction_count',
                'wallet_funding_total',
            ]);
            // Only meaningful for transaction_volume/transaction_count — a
            // Transaction::transaction_type value (e.g. 'airtime_recharge')
            // to scope to one service. Null = across all services.
            $table->string('service_type')->nullable();
            $table->decimal('threshold', 15, 2);

            // false = award once, the first time the metric crosses
            // threshold. true = award again every additional multiple of
            // threshold reached (e.g. every 5 referrals).
            $table->boolean('repeatable')->default(false);

            $table->enum('reward_type', ['badge', 'cash', 'both']);
            $table->string('badge_name')->nullable();
            $table->string('badge_icon')->nullable();
            $table->decimal('cash_amount', 15, 2)->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
