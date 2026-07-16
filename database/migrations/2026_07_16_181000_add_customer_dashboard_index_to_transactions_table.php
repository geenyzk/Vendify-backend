<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', fn (Blueprint $table) =>
            $table->index(['user_id', 'created_at'], 'transactions_user_created_index'));
    }

    public function down(): void
    {
        Schema::table('transactions', fn (Blueprint $table) =>
            $table->dropIndex('transactions_user_created_index'));
    }
};
