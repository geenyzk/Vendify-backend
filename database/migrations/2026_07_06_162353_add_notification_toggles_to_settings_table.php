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
        if (Schema::hasColumn('settings', 'notify_admin_on_airtime_to_cash')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('notify_admin_on_airtime_to_cash')->default(true);
            $table->boolean('notify_admin_on_wallet_withdrawal')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['notify_admin_on_airtime_to_cash', 'notify_admin_on_wallet_withdrawal']);
        });
    }
};
