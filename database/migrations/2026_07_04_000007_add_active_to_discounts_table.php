<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The `active` column already exists on some environments' `discounts`
     * table despite no migration creating it. This formalizes it so fresh
     * installs get the same column.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('discounts', 'active')) {
            Schema::table('discounts', function (Blueprint $table) {
                $table->boolean('active')->default(true)->after('max');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('discounts', 'active')) {
            Schema::table('discounts', function (Blueprint $table) {
                $table->dropColumn('active');
            });
        }
    }
};
