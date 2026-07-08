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
        // Schema drift (dump imports): the table may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasTable('disco_provider_ids')) {
            return;
        }

        // Split out of the `discounts` table, where these columns used to
        // live before `2026_07_04_000010_drop_dead_columns_from_discounts_table`
        // dropped them as "dead" — they were actually still read live by
        // Adex/SMEPlug/vtpass to map a generic disco name (e.g. "Ikeja
        // Electric") to each vendor's own internal ID for that disco. That
        // drop broke electricity bill payments; this restores the same
        // columns under their own table, decoupled from pricing.
        Schema::create('disco_provider_ids', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            for ($i = 1; $i <= 5; $i++) {
                $table->string("adex_server_{$i}")->nullable();
                $table->string("spurs_server_{$i}")->nullable();
                $table->string("msorg_server_{$i}")->nullable();
            }
            $table->string('vtpass')->nullable();
            $table->string('payscribe')->nullable();
            $table->string('sme_plug')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disco_provider_ids');
    }
};
