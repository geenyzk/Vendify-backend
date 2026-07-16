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
        if (!Schema::hasIndex('transactions', 'transactions_user_created_index')) {
            Schema::table('transactions', fn (Blueprint $table) =>
                $table->index(['user_id', 'created_at'], 'transactions_user_created_index'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('transactions', 'transactions_user_created_index')) {
            Schema::table('transactions', fn (Blueprint $table) =>
                $table->dropIndex('transactions_user_created_index'));
        }
    }
};
