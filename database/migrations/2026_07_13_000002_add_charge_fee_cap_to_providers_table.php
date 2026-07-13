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
        if (!Schema::hasColumn('providers', 'charge_fee_cap')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->decimal('charge_fee_cap', 15, 2)->nullable()->after('charge_fee');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('providers', 'charge_fee_cap')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->dropColumn('charge_fee_cap');
            });
        }
    }
};
