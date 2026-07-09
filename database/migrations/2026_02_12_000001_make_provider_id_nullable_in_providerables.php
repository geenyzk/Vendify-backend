<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Nothing to alter when the table/column isn't there — the guarded
        // create_providerables migration covers fresh installs. Re-running
        // the MODIFY on an already-nullable column is harmless.
        if (!Schema::hasColumn('providerables', 'provider_id')) {
            return;
        }

        // Make provider_id nullable. Use raw SQL if the change() helper is not available.
        try {
            Schema::table('providerables', function (Blueprint $table) {
                $table->unsignedBigInteger('provider_id')->nullable()->change();
            });
        } catch (\Throwable $e) {
            // Fallback: use raw SQL for MySQL
            $driver = DB::getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE providerables MODIFY COLUMN provider_id BIGINT UNSIGNED NULL;');
            } else {
                throw $e;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('providerables', function (Blueprint $table) {
                $table->unsignedBigInteger('provider_id')->nullable(false)->change();
            });
        } catch (\Throwable $e) {
            $driver = DB::getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE providerables MODIFY COLUMN provider_id BIGINT UNSIGNED NOT NULL;');
            } else {
                throw $e;
            }
        }
    }
};
