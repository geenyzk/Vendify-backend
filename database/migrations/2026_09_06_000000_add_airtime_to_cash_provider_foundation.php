<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airtime_to_cash_requests', function (Blueprint $table) {
            $table->string('processing_mode', 16)->default('manual')->index();
            $table->unsignedBigInteger('network_id')->nullable(); // Historical rows remain untouched.
            $table->string('provider', 64)->nullable();
            $table->string('provider_reference', 40)->nullable()->unique();
            $table->text('provider_identifier')->nullable(); // Encrypted cast, never serialized.
            $table->string('provider_status', 32)->nullable()->index();
            $table->string('provider_message')->nullable(); // Controlled internal messages only.
            $table->timestamp('provider_started_at')->nullable();
            $table->timestamp('provider_confirmed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_provider_check_at')->nullable();
            $table->unsignedInteger('provider_attempt_count')->default(0);
            $table->unsignedInteger('otp_attempt_count')->default(0);
            $table->json('rate_snapshot')->nullable();
            $table->json('provider_metadata')->nullable(); // Allowlisted numeric facts only.
            $table->decimal('provider_cost', 15, 2)->nullable();
            $table->decimal('provider_fee', 15, 2)->nullable();
            $table->string('start_key', 64)->nullable()->unique();
            $table->string('active_session_key', 64)->nullable()->unique();
        });
        Schema::create('airtime_to_cash_provider_settings', function (Blueprint $table) {
            $table->string('provider', 64)->primary();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never roll back while provider sessions/evidence remain in use.
        if (DB::table('airtime_to_cash_requests')->where('processing_mode', 'provider')->exists()) {
            throw new RuntimeException('Provider conversion records exist; archive and review before rolling back this migration.');
        }
        Schema::dropIfExists('airtime_to_cash_provider_settings');
        Schema::table('airtime_to_cash_requests', function (Blueprint $table) {
            $table->dropUnique(['provider_reference']);
            $table->dropUnique(['start_key']);
            $table->dropUnique(['active_session_key']);
            $table->dropIndex(['processing_mode']);
            $table->dropIndex(['provider_status']);
            $table->dropColumn(['processing_mode', 'network_id', 'provider', 'provider_reference', 'provider_identifier',
                'provider_status', 'provider_message', 'provider_started_at', 'provider_confirmed_at', 'expires_at',
                'last_provider_check_at', 'provider_attempt_count', 'otp_attempt_count', 'rate_snapshot',
                'provider_metadata', 'provider_cost', 'provider_fee', 'start_key', 'active_session_key']);
        });
    }
};
