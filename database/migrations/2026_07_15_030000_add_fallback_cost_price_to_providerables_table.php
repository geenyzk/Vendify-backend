<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan's fallback provider buys the same plan at its own price, which had
 * nowhere to live: the fallback migration added fallback_provider_id and
 * fallback_server_id but no cost. Every sale served by the fallback therefore
 * recorded the PRIMARY provider's cost_price, so "profit = amount - cost" on
 * the admin dashboard was wrong for exactly the sales that failed over.
 *
 * Nullable on purpose: existing rows (and plans whose fallback genuinely costs
 * the same) leave it null and keep reading cost_price, so nothing changes for
 * them until an admin sets a distinct fallback cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('providerables')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            if (!Schema::hasColumn('providerables', 'fallback_cost_price')) {
                $table->decimal('fallback_cost_price', 10, 2)->nullable()->after('cost_price');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('providerables')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            if (Schema::hasColumn('providerables', 'fallback_cost_price')) {
                $table->dropColumn('fallback_cost_price');
            }
        });
    }
};
