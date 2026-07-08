<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops columns superseded by discount_role (per-role pricing) and the
     * never-consumed vendor "server" slots (HasServers::getServersAttribute()
     * has no callers anywhere in the app — confirmed dead).
     */
    public function up(): void
    {
        // Schema drift (dump imports): these columns may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasColumn('discounts', 'user_discount')) {
            return;
        }

        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn([
                'user_discount',
                'bonanza_discount',
                'agent_discount',
                'api_discount',
                'adex_server_1',
                'adex_server_2',
                'adex_server_3',
                'adex_server_4',
                'adex_server_5',
                'spurs_server_1',
                'spurs_server_2',
                'spurs_server_3',
                'spurs_server_4',
                'spurs_server_5',
                'msorg_server_1',
                'msorg_server_2',
                'msorg_server_3',
                'msorg_server_4',
                'msorg_server_5',
                'vtpass',
                'payscribe',
                'sme_plug',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->decimal('user_discount', 10, 2)->nullable()->default(0);
            $table->decimal('bonanza_discount', 10, 2)->nullable()->default(0);
            $table->decimal('agent_discount', 10, 2)->nullable()->default(0);
            $table->decimal('api_discount', 10, 2)->nullable()->default(0);
            for ($i = 1; $i <= 5; $i++) {
                $table->string("adex_server_$i")->nullable();
                $table->string("spurs_server_$i")->nullable();
                $table->string("msorg_server_$i")->nullable();
            }
            $table->string('vtpass')->nullable();
            $table->string('payscribe')->nullable();
            $table->string('sme_plug')->nullable();
        });
    }
};
