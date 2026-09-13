<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airtime_to_cash_provider_settings', function (Blueprint $table) {
            $table->string('base_url')->nullable();
            $table->text('token')->nullable();
            $table->string('health_status', 24)->default('not_checked');
            $table->string('health_message')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
        });

        Schema::table('settings', function (Blueprint $table) {
            // NULL preserves the existing environment value until an admin
            // deliberately creates a database override.
            $table->boolean('airtime_to_cash_provider_mode_enabled')->nullable();
            $table->boolean('airtime_to_cash_live_calls_enabled')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'airtime_to_cash_provider_mode_enabled',
                'airtime_to_cash_live_calls_enabled',
            ]);
        });

        Schema::table('airtime_to_cash_provider_settings', function (Blueprint $table) {
            $table->dropColumn([
                'base_url', 'token', 'health_status', 'health_message', 'last_health_check_at',
            ]);
        });
    }
};
