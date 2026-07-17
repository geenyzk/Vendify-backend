<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20)->default('web');
            $table->string('laravel_session_id')->nullable()->index();
            $table->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('device_id', 128)->nullable();
            $table->string('device_name')->nullable();
            $table->string('device_type', 40)->nullable();
            $table->string('platform', 80)->nullable();
            $table->string('browser', 80)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('approximate_location')->nullable();
            $table->timestamp('last_active_at')->nullable()->index();
            $table->timestamp('idle_expires_at')->nullable();
            $table->timestamp('absolute_expires_at')->index();
            $table->timestamp('reauthenticated_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index(['user_id', 'device_id']);
        });

        Schema::create('auth_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('auth_session_id');
            $table->char('token_hash', 64)->unique();
            $table->foreignId('replaced_by_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('auth_session_id')->references('id')->on('auth_sessions')->cascadeOnDelete();
            $table->foreign('replaced_by_id')->references('id')->on('auth_refresh_tokens')->nullOnDelete();
        });

        // Legacy Sanctum tokens were issued without expiry. Give them a short
        // migration window instead of leaving replayable credentials valid
        // forever. New logins receive explicit short expirations.
        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->update(['expires_at' => now()->addMinutes(30)]);
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_refresh_tokens');
        Schema::dropIfExists('auth_sessions');
    }
};
