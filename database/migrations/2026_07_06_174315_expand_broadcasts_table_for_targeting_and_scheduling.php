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
        Schema::table('broadcasts', function (Blueprint $table) {
            // Admin's own label for finding this broadcast again later — not
            // shown to recipients.
            $table->string('name')->nullable()->after('id');
            // The full validated request (audience filters + per-channel
            // content) — needed to actually execute a scheduled broadcast
            // later (see App\Console\Commands\SendScheduledBroadcasts) and
            // to let an admin duplicate a past broadcast.
            $table->json('payload')->nullable()->after('channels');
            $table->unsignedInteger('recipient_count')->default(0)->after('audience_label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn(['name', 'payload', 'recipient_count']);
        });
    }
};
