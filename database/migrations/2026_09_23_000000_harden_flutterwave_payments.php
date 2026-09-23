<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'provider_transaction_id')) {
                $table->string('provider_transaction_id')->nullable()->after('payment_reference');
                $table->unique(['provider', 'provider_transaction_id'], 'transactions_provider_payment_unique');
            }
        });

        Schema::table('wallet_withdrawals', function (Blueprint $table) {
            if (!Schema::hasColumn('wallet_withdrawals', 'gateway_transfer_id')) {
                $table->string('gateway_transfer_id')->nullable()->unique()->after('gateway_reference');
            }
            if (!Schema::hasColumn('wallet_withdrawals', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('reviewed_at');
            }
            if (!Schema::hasColumn('wallet_withdrawals', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('completed_at');
            }
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE wallet_withdrawals MODIFY status ENUM('pending','processing','completed','successful','failed','rejected') NOT NULL DEFAULT 'pending'");
            DB::table('wallet_withdrawals')->where('status', 'completed')->update(['status' => 'successful']);
            DB::statement("ALTER TABLE wallet_withdrawals MODIFY status ENUM('pending','processing','successful','failed','rejected') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('wallet_withdrawals', function (Blueprint $table) {
                $table->string('status', 32)->default('pending')->change();
            });
            DB::table('wallet_withdrawals')->where('status', 'completed')->update(['status' => 'successful']);
        }

        Schema::table('providers', function (Blueprint $table) {
            $table->string('webhook_access')->nullable()->default(null)->change();
        });
        DB::table('providers')->where('webhook_access', '1')->update(['webhook_access' => null]);

        $flutterwaveConfigured = DB::table('providers')
            ->whereRaw('LOWER(name) = ?', ['flutterwave'])
            ->where('category', 'payment')
            ->where('active', true)
            ->whereNotNull('secret_key')
            ->where('secret_key', '!=', '')
            ->whereNotNull('webhook_access')
            ->where('webhook_access', '!=', '')
            ->exists();
        if (!$flutterwaveConfigured) {
            DB::table('service_controls')
                ->whereRaw('LOWER(name) = ?', ['flutterwave'])
                ->update(['isActive' => false]);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE wallet_withdrawals MODIFY status ENUM('pending','processing','completed','successful','failed','rejected') NOT NULL DEFAULT 'pending'");
        }
        DB::table('wallet_withdrawals')->where('status', 'successful')->update(['status' => 'completed']);
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE wallet_withdrawals MODIFY status ENUM('pending','completed','failed','rejected') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'provider_transaction_id')) {
                $table->dropUnique('transactions_provider_payment_unique');
                $table->dropColumn('provider_transaction_id');
            }
        });

        Schema::table('wallet_withdrawals', function (Blueprint $table) {
            foreach (['gateway_transfer_id', 'completed_at', 'refunded_at'] as $column) {
                if (Schema::hasColumn('wallet_withdrawals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
