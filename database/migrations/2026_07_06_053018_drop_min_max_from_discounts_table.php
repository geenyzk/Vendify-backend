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
        if (Schema::hasColumn('discounts', 'min')) {
            return;
        }

        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn(['min', 'max']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->decimal('min', 15, 2)->nullable();
            $table->decimal('max', 15, 2)->nullable();
        });
    }
};
