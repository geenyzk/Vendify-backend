<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Automated Airtime-to-Cash gets its own per-network switch, independent of
     * the manual method's airtime_to_cash_active toggle and destination number.
     */
    public function up(): void
    {
        // Schema drift (dump imports): skip, and never re-run the backfill below.
        if (Schema::hasColumn('networks', 'airtime_to_cash_automated_active')) {
            return;
        }

        Schema::table('networks', function (Blueprint $table) {
            // New networks never offer automation until an admin enables it.
            $table->boolean('airtime_to_cash_automated_active')->default(false);
        });

        // One-time copy. Automation used to follow the manual toggle, so only
        // networks that already offered it keep it; others need explicit opt-in.
        DB::table('networks')->where('airtime_to_cash_active', true)
            ->update(['airtime_to_cash_automated_active' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('networks', 'airtime_to_cash_automated_active')) {
            Schema::table('networks', function (Blueprint $table) {
                $table->dropColumn('airtime_to_cash_automated_active');
            });
        }
    }
};
