<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'users_status_created_index');
            $table->index(['is_verified', 'email_verified_at'], 'users_verification_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_status_created_index');
            $table->dropIndex('users_verification_index');
        });
    }
};
