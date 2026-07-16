<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Guarded so a re-run after a partially-applied batch doesn't die on
            // "Duplicate key name" (MySQL 1061). MySQL DDL auto-commits, so an
            // index created before a later statement failed is left behind while
            // the migration itself stays unrecorded — the next run must skip it.
            if (!Schema::hasIndex('users', 'users_status_created_index')) {
                $table->index(['status', 'created_at'], 'users_status_created_index');
            }
            if (!Schema::hasIndex('users', 'users_verification_index')) {
                $table->index(['is_verified', 'email_verified_at'], 'users_verification_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasIndex('users', 'users_status_created_index')) {
                $table->dropIndex('users_status_created_index');
            }
            if (Schema::hasIndex('users', 'users_verification_index')) {
                $table->dropIndex('users_verification_index');
            }
        });
    }
};
