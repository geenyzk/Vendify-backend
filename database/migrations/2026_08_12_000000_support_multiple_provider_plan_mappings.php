<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('providerables')) {
            return;
        }

        // Older Vendify releases allowed only one provider row per plan. A
        // plan is now intentionally backed by zero or more provider mappings.
        try {
            Schema::table('providerables', fn (Blueprint $table) =>
                $table->dropUnique('providerables_unique_providerable'));
        } catch (\Throwable) {
            try {
                DB::statement('DROP INDEX providerables_unique_providerable');
            } catch (\Throwable) {
                // The index is absent on some imported databases.
            }
        }

        Schema::table('providerables', function (Blueprint $table) {
            if (! Schema::hasColumn('providerables', 'provider_service_id')) {
                $table->string('provider_service_id')->nullable()->after('external_plan_id');
            }
            if (! Schema::hasColumn('providerables', 'provider_plan_name')) {
                $table->string('provider_plan_name')->nullable()->after('provider_service_id');
            }
            if (! Schema::hasColumn('providerables', 'provider_available')) {
                $table->boolean('provider_available')->default(true)->after('provider_plan_name');
            }
            if (! Schema::hasColumn('providerables', 'provider_enabled')) {
                $table->boolean('provider_enabled')->default(true)->after('provider_available');
            }
            if (! Schema::hasColumn('providerables', 'priority')) {
                $table->unsignedInteger('priority')->default(100)->after('provider_enabled');
            }
            if (! Schema::hasColumn('providerables', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('priority');
            }
        });

    }

    public function down(): void
    {
        if (! Schema::hasTable('providerables')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            foreach (['provider_service_id', 'provider_plan_name', 'provider_available', 'provider_enabled', 'priority', 'last_synced_at'] as $column) {
                if (Schema::hasColumn('providerables', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
