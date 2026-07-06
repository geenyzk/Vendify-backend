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
        Schema::table('networks', function (Blueprint $table) {
            $table->string('airtime_to_cash_destination_number')->nullable();
            $table->decimal('airtime_to_cash_min', 10, 2)->default(100);
            $table->decimal('airtime_to_cash_max', 10, 2)->default(50000);
            $table->boolean('airtime_to_cash_active')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn([
                'airtime_to_cash_destination_number',
                'airtime_to_cash_min',
                'airtime_to_cash_max',
                'airtime_to_cash_active',
            ]);
        });
    }
};
