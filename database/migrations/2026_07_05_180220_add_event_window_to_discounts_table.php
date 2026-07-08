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
        // Schema drift (dump imports): these columns may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasColumn('discounts', 'starts_at')) {
            return;
        }

        Schema::table('discounts', function (Blueprint $table) {
            // Null on both = always-on discount (legacy behavior, unchanged).
            // Set both = a scheduled "event" that only applies within the
            // window, e.g. a Black Friday price-slash from Nov 24 to Nov 27.
            $table->date('starts_at')->nullable()->after('active');
            $table->date('ends_at')->nullable()->after('starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
