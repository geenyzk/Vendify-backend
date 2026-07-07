<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_instances', function (Blueprint $table) {
            // Self-registration flow: admin generates a short-lived,
            // single-use code (just needs a name), hands it to whoever is
            // setting up the child app, and the child calls
            // POST /api/child/register with it to receive its real
            // slug + shared_secret. shared_secret/status stay null/'pending'
            // until that happens — no admin form for the full connection
            // details anymore.
            $table->string('registration_code')->nullable()->unique()->after('slug');
            $table->timestamp('registration_code_expires_at')->nullable()->after('registration_code');
            $table->timestamp('registered_at')->nullable()->after('registration_code_expires_at');
        });

        // shared_secret was NOT NULL-by-convention (always generated on
        // create) — now it's genuinely null until self-registration
        // completes. Using raw SQL rather than Schema::table()->change()
        // since that requires doctrine/dbal, which isn't installed.
        DB::statement('ALTER TABLE child_instances MODIFY shared_secret TEXT NULL');

        // New instances start 'pending' (registration code generated, not
        // yet used) rather than 'active' — they become 'active' only once
        // the child actually completes self-registration.
        DB::statement("ALTER TABLE child_instances MODIFY status ENUM('pending', 'active', 'paused', 'revoked') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        Schema::table('child_instances', function (Blueprint $table) {
            $table->dropColumn(['registration_code', 'registration_code_expires_at', 'registered_at']);
        });
        DB::statement('ALTER TABLE child_instances MODIFY shared_secret TEXT NOT NULL');
        DB::statement("ALTER TABLE child_instances MODIFY status ENUM('active', 'paused', 'revoked') NOT NULL DEFAULT 'active'");
    }
};
