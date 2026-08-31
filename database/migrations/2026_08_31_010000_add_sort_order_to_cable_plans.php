<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cable_plans') && ! Schema::hasColumn('cable_plans', 'sort_order')) {
            Schema::table('cable_plans', fn (Blueprint $table) =>
                $table->unsignedInteger('sort_order')->default(0)->after('active'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cable_plans') && Schema::hasColumn('cable_plans', 'sort_order')) {
            Schema::table('cable_plans', fn (Blueprint $table) => $table->dropColumn('sort_order'));
        }
    }
};
