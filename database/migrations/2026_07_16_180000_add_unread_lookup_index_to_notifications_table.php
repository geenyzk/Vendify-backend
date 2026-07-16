<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded so re-running after a partially-applied batch is a no-op
        // instead of a "Duplicate key name" (MySQL 1061) failure.
        if (!Schema::hasIndex('notifications', 'notifications_unread_lookup_index')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index(
                    ['notifiable_type', 'notifiable_id', 'read_at'],
                    'notifications_unread_lookup_index'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('notifications', 'notifications_unread_lookup_index')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropIndex('notifications_unread_lookup_index');
            });
        }
    }
};
