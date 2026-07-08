<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The original create_broadcasts migration is no longer in the repo (lost in
// the same restructuring as the roles tables), so this migration doubles as
// the create on fresh installs and as the expansion on legacy databases that
// still carry the pre-targeting table. Column set mirrors App\Models\Broadcast.
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('broadcasts')) {
            Schema::create('broadcasts', function (Blueprint $table) {
                $table->id();
                // Admin's own label for finding this broadcast again later —
                // not shown to recipients.
                $table->string('name')->nullable();
                $table->string('title')->nullable();
                $table->text('message')->nullable();
                $table->json('channels');
                // The full validated request (audience filters + per-channel
                // content) — needed to actually execute a scheduled broadcast
                // later (see App\Console\Commands\SendScheduledBroadcasts) and
                // to let an admin duplicate a past broadcast.
                $table->json('payload')->nullable();
                $table->string('audience_label')->nullable();
                $table->unsignedInteger('recipient_count')->default(0);
                $table->timestamp('scheduled_at')->nullable();
                $table->boolean('sent')->default(false);
                $table->timestamps();
            });

            return;
        }

        Schema::table('broadcasts', function (Blueprint $table) {
            if (!Schema::hasColumn('broadcasts', 'name')) {
                $table->string('name')->nullable()->after('id');
            }
            if (!Schema::hasColumn('broadcasts', 'payload')) {
                $table->json('payload')->nullable()->after('channels');
            }
            if (!Schema::hasColumn('broadcasts', 'recipient_count')) {
                $table->unsignedInteger('recipient_count')->default(0)->after('audience_label');
            }
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
