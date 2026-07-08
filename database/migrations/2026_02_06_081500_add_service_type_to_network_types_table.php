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
        if (!Schema::hasColumn('network_types', 'service_type')) {
            Schema::table('network_types', function (Blueprint $table) {
                $table->string('service_type')->default('airtime')->after('active');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('network_types', 'service_type')) {
            Schema::table('network_types', function (Blueprint $table) {
                $table->dropColumn('service_type');
            });
        }
    }
};
