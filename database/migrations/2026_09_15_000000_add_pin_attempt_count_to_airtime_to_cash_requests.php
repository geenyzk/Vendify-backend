<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provider-rejected transfer PINs per automated conversion. Only an
     * invalid_pin classification increments it; the PIN itself is never stored.
     */
    public function up(): void
    {
        // Schema drift (dump imports): skip instead of aborting the run.
        if (Schema::hasColumn('airtime_to_cash_requests', 'pin_attempt_count')) {
            return;
        }

        Schema::table('airtime_to_cash_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('pin_attempt_count')->default(0);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('airtime_to_cash_requests', 'pin_attempt_count')) {
            Schema::table('airtime_to_cash_requests', function (Blueprint $table) {
                $table->dropColumn('pin_attempt_count');
            });
        }
    }
};
