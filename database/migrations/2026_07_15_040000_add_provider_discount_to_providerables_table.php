<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Airtime has no fixed cost_price — it is sold at face value, and the provider
 * bills us less than face by an agreed commission. That discount IS the cost
 * basis for airtime, and it had nowhere to live, so airtime sales carried no
 * cost at all and were silently excluded from the profit figure.
 *
 * Stored per provider (primary + fallback) on the same routing row as the
 * costs, because each vendor negotiates its own discount. Percentages, e.g.
 * 3.50 means the provider charges us 96.5% of face value.
 *
 * Nullable: null means "no known discount", and airtime keeps its previous
 * behaviour of contributing no cost rather than being recorded at zero cost
 * (which would book the entire sale as profit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('providerables')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            if (!Schema::hasColumn('providerables', 'provider_discount')) {
                $table->decimal('provider_discount', 5, 2)->nullable()->after('fallback_cost_price');
            }

            if (!Schema::hasColumn('providerables', 'fallback_provider_discount')) {
                $table->decimal('fallback_provider_discount', 5, 2)->nullable()->after('provider_discount');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('providerables')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            foreach (['provider_discount', 'fallback_provider_discount'] as $column) {
                if (Schema::hasColumn('providerables', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
