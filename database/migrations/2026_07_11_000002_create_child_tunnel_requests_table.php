<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotency ledger for the route-tunneling endpoints
        // (ChildTunnelController): a child retries stuck transactions with
        // the same request-id, so each (user, request_id) vend outcome is
        // recorded once and replayed from here instead of charging twice.
        Schema::create('child_tunnel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('request_id');
            $table->string('service', 20);
            $table->string('status', 20);
            $table->string('response_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_tunnel_requests');
    }
};
