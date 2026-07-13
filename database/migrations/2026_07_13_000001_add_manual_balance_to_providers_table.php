<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('providers', 'manual_balance')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->decimal('manual_balance', 15, 2)->nullable()->after('auto_fund_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('providers', 'manual_balance')) {
            Schema::table('providers', function (Blueprint $table) {
                $table->dropColumn('manual_balance');
            });
        }
    }
};
