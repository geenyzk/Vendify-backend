<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Schema drift (dump imports): these columns may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasColumn('transactions', 'related_reference')) {
            return;
        }

        DB::statement("ALTER TABLE transactions MODIFY transaction_type ENUM(
            'airtime_recharge', 'data_subscription', 'cable_subscription', 'electric_bill', 'exam',
            'betting_funding', 'airtime_pin', 'data_pin', 'wallet_funding', 'manual_funding', 'bulksms',
            'wallet_transfer_in', 'wallet_transfer_out', 'wallet_withdrawal'
        ) NOT NULL");

        Schema::table('transactions', function (Blueprint $table) {
            // Links a pair of related ledger rows: the sender/recipient side
            // of a wallet-to-wallet transfer, or a withdrawal's reservation
            // debit and its resulting gateway payout record.
            $table->string('related_reference')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('related_reference');
        });

        DB::statement("ALTER TABLE transactions MODIFY transaction_type ENUM(
            'airtime_recharge', 'data_subscription', 'cable_subscription', 'electric_bill', 'exam',
            'betting_funding', 'airtime_pin', 'data_pin', 'wallet_funding', 'manual_funding', 'bulksms'
        ) NOT NULL");
    }
};
