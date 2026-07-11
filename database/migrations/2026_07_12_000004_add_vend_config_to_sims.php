<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SIM vend configuration moves out of the hub's local config.json and
     * into the database, managed from the admin panel: the carrier
     * transfer-service PIN (encrypted at rest — it authorizes moving real
     * airtime) and the balance-check USSD code. The hub pulls these via
     * the signed GET /api/sim/{slug}/config endpoint.
     */
    public function up(): void
    {
        Schema::table('sims', function (Blueprint $table) {
            $table->text('transfer_pin')->nullable()->after('data_low_threshold_mb');
            $table->string('balance_ussd')->nullable()->after('transfer_pin');
        });
    }

    public function down(): void
    {
        Schema::table('sims', function (Blueprint $table) {
            $table->dropColumn(['transfer_pin', 'balance_ussd']);
        });
    }
};
