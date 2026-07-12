<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-day aggregates the AI monitor keeps for itself (see
        // HealthSweeper) so it can compare a day against the same weekday in
        // previous weeks and flag trend anomalies, not just fixed thresholds.
        Schema::create('ai_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('stat_date')->unique();
            $table->unsignedInteger('tx_count')->default(0);
            $table->decimal('tx_volume', 18, 2)->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('signups')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_daily_stats');
    }
};
