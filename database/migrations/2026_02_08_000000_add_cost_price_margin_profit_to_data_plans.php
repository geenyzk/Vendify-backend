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
        if (Schema::hasColumn('data_plans', 'vtpass_cost_price')) {
            return;
        }

        Schema::table('data_plans', function (Blueprint $table) {
            // ADEX servers - cost price and margin profit
            for ($i = 1; $i <= 5; $i++) {
                $table->decimal("adex_server_{$i}_cost_price", 10, 2)->default(0)->after("adex_server_{$i}");
                $table->decimal("adex_server_{$i}_margin_profit", 10, 2)->default(0)->after("adex_server_{$i}_cost_price");
            }

            // SPURS servers - cost price and margin profit
            for ($i = 1; $i <= 5; $i++) {
                $table->decimal("spurs_server_{$i}_cost_price", 10, 2)->default(0)->after("spurs_server_{$i}");
                $table->decimal("spurs_server_{$i}_margin_profit", 10, 2)->default(0)->after("spurs_server_{$i}_cost_price");
            }

            // MSORG servers - cost price and margin profit
            for ($i = 1; $i <= 5; $i++) {
                $table->decimal("msorg_server_{$i}_cost_price", 10, 2)->default(0)->after("msorg_server_{$i}");
                $table->decimal("msorg_server_{$i}_margin_profit", 10, 2)->default(0)->after("msorg_server_{$i}_cost_price");
            }

            // VTPASS - cost price and margin profit
            $table->decimal('vtpass_cost_price', 10, 2)->default(0)->after('vtpass');
            $table->decimal('vtpass_margin_profit', 10, 2)->default(0)->after('vtpass_cost_price');

            // PAYSCRIBE - cost price and margin profit
            $table->decimal('payscribe_cost_price', 10, 2)->default(0)->after('payscribe');
            $table->decimal('payscribe_margin_profit', 10, 2)->default(0)->after('payscribe_cost_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_plans', function (Blueprint $table) {
            // Drop ADEX columns
            for ($i = 1; $i <= 5; $i++) {
                $table->dropColumn("adex_server_{$i}_cost_price");
                $table->dropColumn("adex_server_{$i}_margin_profit");
            }

            // Drop SPURS columns
            for ($i = 1; $i <= 5; $i++) {
                $table->dropColumn("spurs_server_{$i}_cost_price");
                $table->dropColumn("spurs_server_{$i}_margin_profit");
            }

            // Drop MSORG columns
            for ($i = 1; $i <= 5; $i++) {
                $table->dropColumn("msorg_server_{$i}_cost_price");
                $table->dropColumn("msorg_server_{$i}_margin_profit");
            }

            // Drop VTPASS columns
            $table->dropColumn('vtpass_cost_price');
            $table->dropColumn('vtpass_margin_profit');

            // Drop PAYSCRIBE columns
            $table->dropColumn('payscribe_cost_price');
            $table->dropColumn('payscribe_margin_profit');
        });
    }
};
