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
        Schema::table('data_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('data_plans', 'server_id')) {
                $table->unsignedBigInteger('server_id')->nullable()->after('id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_plans', function (Blueprint $table) {
            if (Schema::hasColumn('data_plans', 'server_id')) {
                $table->dropColumn('server_id');
            }
        });
    }
};
