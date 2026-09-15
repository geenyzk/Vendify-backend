<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-managed "session login before transfer" per provider. Null means "not set
     * in Admin": the config/env default applies, which is off.
     */
    public function up(): void
    {
        // Schema drift (dump imports): skip instead of aborting the run.
        if (Schema::hasColumn('airtime_to_cash_provider_settings', 'session_login_before_transfer')) {
            return;
        }

        Schema::table('airtime_to_cash_provider_settings', function (Blueprint $table) {
            $table->boolean('session_login_before_transfer')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('airtime_to_cash_provider_settings', 'session_login_before_transfer')) {
            Schema::table('airtime_to_cash_provider_settings', function (Blueprint $table) {
                $table->dropColumn('session_login_before_transfer');
            });
        }
    }
};
