<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'status')) {
            return;
        }

        // The original schema used ban/suspend while the application uses
        // banned/suspended. A string column avoids enum drift across MySQL and
        // SQLite and makes suspension enforcement deterministic.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY status VARCHAR(32) NOT NULL DEFAULT 'active'");
        }

        DB::table('users')->where('status', 'ban')->update(['status' => 'banned']);
        DB::table('users')->where('status', 'suspend')->update(['status' => 'suspended']);
    }

    public function down(): void
    {
        DB::table('users')->where('status', 'banned')->update(['status' => 'ban']);
        DB::table('users')->where('status', 'suspended')->update(['status' => 'suspend']);
    }
};
