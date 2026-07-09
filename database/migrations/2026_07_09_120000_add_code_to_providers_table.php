<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vendor and payment-gateway admin forms have always collected a
     * short display code (e.g. "FLW", "ADX") and the list/detail pages
     * render it — but the column never existed, so the Universal Table API
     * silently dropped it on every save. Make the field real.
     */
    public function up(): void
    {
        if (Schema::hasColumn('providers', 'code')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('providers', 'code')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }
    }
};
