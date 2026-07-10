<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vendor cost of goods for a sale (what the platform paid the vendor),
     * recorded at purchase time so profit = amount − cost is computable on the
     * dashboard. Nullable: services/rows without a known cost (and every sale
     * made before this column existed) are simply excluded from profit rather
     * than counted as pure margin.
     */
    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'cost')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('cost', 15, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('transactions', 'cost')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
