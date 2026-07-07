<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw inbound audit/idempotency log — one row per push batch a
        // child instance sends, keyed so a retried/duplicate delivery
        // (e.g. the child never saw our 2xx and resends) is a no-op.
        Schema::create('child_sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_instance_id')->constrained()->cascadeOnDelete();
            $table->string('event_id');
            $table->string('resource')->nullable();
            $table->unsignedInteger('record_count')->default(0);
            $table->timestamps();

            $table->unique(['child_instance_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_sync_events');
    }
};
