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
        // Schema drift (dump imports): some databases already dropped these —
        // only drop the ones still present so the run doesn't abort.
        $present = array_values(array_filter(
            ['min', 'max'],
            fn (string $column) => Schema::hasColumn('discounts', $column),
        ));

        if ($present === []) {
            return;
        }

        Schema::table('discounts', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
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
