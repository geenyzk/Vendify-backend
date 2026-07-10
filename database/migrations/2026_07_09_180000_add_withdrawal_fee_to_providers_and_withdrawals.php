<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-gateway withdrawal fee (deposit fees already exist via
     * providers.charge_fee/charge_type, applied in PaymentBase::creditedAmount).
     * `fee` on wallet_withdrawals records what was actually charged on a given
     * request so refunds can return amount + fee.
     */
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            if (!Schema::hasColumn('providers', 'withdrawal_fee')) {
                $table->decimal('withdrawal_fee', 15, 2)->default(0)->after('charge_type');
            }
            if (!Schema::hasColumn('providers', 'withdrawal_fee_type')) {
                // 'fiat' (flat naira) or 'percent' — same vocabulary as charge_type.
                $table->string('withdrawal_fee_type')->default('fiat')->after('withdrawal_fee');
            }
        });

        Schema::table('wallet_withdrawals', function (Blueprint $table) {
            if (!Schema::hasColumn('wallet_withdrawals', 'fee')) {
                $table->decimal('fee', 15, 2)->default(0)->after('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            foreach (['withdrawal_fee', 'withdrawal_fee_type'] as $col) {
                if (Schema::hasColumn('providers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('wallet_withdrawals', function (Blueprint $table) {
            if (Schema::hasColumn('wallet_withdrawals', 'fee')) {
                $table->dropColumn('fee');
            }
        });
    }
};
