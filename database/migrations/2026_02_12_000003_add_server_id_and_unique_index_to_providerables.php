<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providerables', function (Blueprint $table) {
            if (!Schema::hasColumn('providerables', 'server_id')) {
                $table->string('server_id')->nullable()->after('provider_id');
            }
        });

        // Create unique index on providerable_id + providerable_type to avoid duplicates
        try {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS providerables_unique_providerable ON providerables (providerable_id, providerable_type);');
        } catch (\Throwable $e) {
            // Some DB drivers don't support IF NOT EXISTS in CREATE INDEX; fallback to schema builder
            try {
                Schema::table('providerables', function (Blueprint $table) {
                    $table->unique(['providerable_id', 'providerable_type'], 'providerables_unique_providerable');
                });
            } catch (\Throwable $ee) {
                // ignore if it already exists
            }
        }
    }

    public function down(): void
    {
        Schema::table('providerables', function (Blueprint $table) {
            if (Schema::hasColumn('providerables', 'server_id')) {
                $table->dropColumn('server_id');
            }
        });

        try {
            DB::statement('DROP INDEX IF EXISTS providerables_unique_providerable;');
        } catch (\Throwable $e) {
            try {
                Schema::table('providerables', function (Blueprint $table) {
                    $table->dropUnique('providerables_unique_providerable');
                });
            } catch (\Throwable $ee) {
                // ignore
            }
        }
    }
};
