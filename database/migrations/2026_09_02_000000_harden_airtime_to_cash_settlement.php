<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'airtime_to_cash_request_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('airtime_to_cash_request_id')->nullable()->after('related_reference');
            });

            // Preserve known history without guessing: only an exact stored
            // payout reference can prove which ledger row paid an old request.
            DB::table('airtime_to_cash_requests')
                ->whereNotNull('payout_transaction_reference')
                ->orderBy('id')
                ->each(function ($request): void {
                    $transactionId = DB::table('transactions')
                        ->where('transaction_reference', $request->payout_transaction_reference)
                        ->value('id');

                    if ($transactionId) {
                        DB::table('transactions')
                            ->where('id', $transactionId)
                            ->whereNull('airtime_to_cash_request_id')
                            ->update(['airtime_to_cash_request_id' => $request->id]);
                    }
                });

            Schema::table('transactions', function (Blueprint $table) {
                $table->unique('airtime_to_cash_request_id', 'transactions_atc_request_unique');
                $table->foreign('airtime_to_cash_request_id', 'transactions_atc_request_foreign')
                    ->references('id')->on('airtime_to_cash_requests')
                    ->nullOnDelete();
            });
        }

        // SQLite stores Laravel enums as text. Production MySQL needs its
        // enum widened explicitly before the new ledger type can be inserted.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY transaction_type ENUM(
                'airtime_recharge', 'data_subscription', 'cable_subscription', 'electric_bill', 'exam',
                'betting_funding', 'airtime_pin', 'data_pin', 'wallet_funding', 'manual_funding', 'bulksms',
                'wallet_transfer_in', 'wallet_transfer_out', 'wallet_withdrawal', 'airtime_to_cash'
            ) NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'airtime_to_cash_request_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropForeign('transactions_atc_request_foreign');
                $table->dropUnique('transactions_atc_request_unique');
                $table->dropColumn('airtime_to_cash_request_id');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::table('transactions')
                ->where('transaction_type', 'airtime_to_cash')
                ->update(['transaction_type' => 'manual_funding']);

            DB::statement("ALTER TABLE transactions MODIFY transaction_type ENUM(
                'airtime_recharge', 'data_subscription', 'cable_subscription', 'electric_bill', 'exam',
                'betting_funding', 'airtime_pin', 'data_pin', 'wallet_funding', 'manual_funding', 'bulksms',
                'wallet_transfer_in', 'wallet_transfer_out', 'wallet_withdrawal'
            ) NOT NULL");
        }
    }
};
