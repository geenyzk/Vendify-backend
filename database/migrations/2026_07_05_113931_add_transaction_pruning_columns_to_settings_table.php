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
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('prune_transactions_enabled')->default(false);
            $table->unsignedInteger('prune_transactions_after_days')->default(365);
            $table->timestamp('transactions_last_pruned_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'prune_transactions_enabled',
                'prune_transactions_after_days',
                'transactions_last_pruned_at',
            ]);
        });
    }
};
