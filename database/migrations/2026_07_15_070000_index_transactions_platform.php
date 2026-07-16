<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `platform` now records the ORIGIN channel of a sale (web / app / api /
 * affiliate / bot / agent) rather than the executing vendor type. Index it so
 * the admin transactions filter and per-channel analytics can slice by it
 * without a full scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transactions') || !Schema::hasColumn('transactions', 'platform')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->index('platform');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['platform']);
        });
    }
};
