<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A sim device is an Android phone (or modem host) running the vending
     * agent. Mirrors child_instances: HMAC-signed requests, one-time-code
     * self-registration (secret stays null until the device registers).
     */
    public function up(): void
    {
        Schema::create('sim_devices', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name');
            // Doubles as the X-Sim-Device header / {slug} route segment.
            $table->string('slug')->unique();
            // Needed in plaintext to recompute the HMAC on each inbound
            // request, so this is `encrypted` (reversible), not hashed.
            $table->text('shared_secret')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('app_version')->nullable();
            $table->json('config')->nullable();
            $table->string('registration_code')->nullable();
            $table->timestamp('registration_code_expires_at')->nullable();
            $table->timestamp('registered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sim_devices');
    }
};
