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
            $table->decimal('wallet_transfer_min', 12, 2)->default(50);
            $table->decimal('wallet_transfer_max', 12, 2)->default(1000000);
            $table->boolean('wallet_withdrawal_auto_approve')->default(false);
            $table->decimal('wallet_withdrawal_min', 12, 2)->default(500);
            $table->decimal('wallet_withdrawal_max', 12, 2)->default(500000);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'wallet_transfer_min',
                'wallet_transfer_max',
                'wallet_withdrawal_auto_approve',
                'wallet_withdrawal_min',
                'wallet_withdrawal_max',
            ]);
        });
    }
};
