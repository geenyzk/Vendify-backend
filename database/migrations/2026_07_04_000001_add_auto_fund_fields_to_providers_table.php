<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->boolean('auto_fund_enabled')->default(false)->after('active');
            $table->decimal('auto_fund_threshold', 15, 2)->nullable()->after('auto_fund_enabled');
            $table->decimal('auto_fund_amount', 15, 2)->nullable()->after('auto_fund_threshold');
            $table->string('account_number', 20)->nullable()->after('auto_fund_amount');
            $table->string('account_name', 100)->nullable()->after('account_number');
            $table->string('bank_code', 10)->nullable()->after('account_name');
            $table->string('bank_name', 100)->nullable()->after('bank_code');
            // Which payment gateway (flutterwave, monnify) to use for the transfer
            $table->unsignedBigInteger('funding_provider_id')->nullable()->after('bank_name');
            $table->foreign('funding_provider_id')->references('id')->on('providers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropForeign(['funding_provider_id']);
            $table->dropColumn([
                'auto_fund_enabled',
                'auto_fund_threshold',
                'auto_fund_amount',
                'account_number',
                'account_name',
                'bank_code',
                'bank_name',
                'funding_provider_id',
            ]);
        });
    }
};
