<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->string('account_name')->nullable()->after('bank_name');
            $table->string('currency', 3)->default('NGN')->after('provider');
            $table->text('failure_reason')->nullable()->after('status');
            $table->unique(['user_id', 'provider'], 'banks_user_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->dropUnique('banks_user_provider_unique');
            $table->dropColumn(['account_name', 'currency', 'failure_reason']);
        });
    }
};
