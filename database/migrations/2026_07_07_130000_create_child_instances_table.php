<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_instances', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Doubles as the {identifier} segment in the webhook route and
            // the X-Child-Instance header the child sends on every request.
            $table->string('slug')->unique();
            $table->string('base_url');
            // Needed in plaintext to recompute the HMAC on each inbound
            // request, so this is `encrypted` (reversible), not hashed.
            $table->text('shared_secret');
            $table->enum('status', ['active', 'paused', 'revoked'])->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('health_status')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_instances');
    }
};
