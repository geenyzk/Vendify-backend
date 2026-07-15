<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('providerables')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            if (! Schema::hasColumn('providerables', 'external_plan_id')) {
                $table->string('external_plan_id')->nullable()->after('server_id');
                $table->unique(
                    ['provider_id', 'external_plan_id'],
                    'providerables_provider_external_plan_unique'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('providerables') || ! Schema::hasColumn('providerables', 'external_plan_id')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            $table->dropUnique('providerables_provider_external_plan_unique');
            $table->dropColumn('external_plan_id');
        });
    }
};
