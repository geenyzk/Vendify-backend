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
        // Schema drift (dump imports): these columns may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasColumn('cable_plans', 'charge_fee')) {
            return;
        }

        Schema::table('cable_plans', function (Blueprint $table) {
            // Cable subscription prices are fixed by the cable company
            // (DStv/GOtv/Startimes) — unlike a flat admin-entered price,
            // the actual cost is resolved from the attached Provider (the
            // same polymorphic `providerables` pivot DataPlan already uses
            // — see CablePlan::providers()/resolveCostPrice()). Replaces
            // the old flat per-role prices with a per-role fee (mirrors
            // DataPlan's `pricing` JSON shape, but additive on top of the
            // provider's cost rather than a full replacement price).
            $table->json('charge_fee')->nullable()->after('plan_name');
            $table->dropColumn(['user_price', 'bonanza_price', 'agent_price', 'api_price']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cable_plans', function (Blueprint $table) {
            $table->dropColumn(['charge_fee']);
            $table->decimal('user_price', 10, 2)->default(0);
            $table->decimal('bonanza_price', 10, 2)->nullable();
            $table->decimal('agent_price', 10, 2)->default(0);
            $table->decimal('api_price', 10, 2)->default(0);
        });
    }
};
