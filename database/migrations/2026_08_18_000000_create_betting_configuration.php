<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('betting_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('betting_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('provider_code')->nullable();
            $table->string('biller_id')->unique();
            $table->boolean('active')->default(false);
            $table->boolean('verification_supported')->default(true);
            $table->decimal('minimum_amount', 15, 2)->default(100);
            $table->decimal('maximum_amount', 15, 2)->default(100000);
            $table->decimal('flat_fee', 15, 2)->default(0);
            $table->decimal('percentage_fee', 8, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('transaction_reference');
            $table->json('raw_payload')->nullable()->after('response_message');
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'raw_payload']);
        });
        Schema::dropIfExists('betting_providers');
        Schema::dropIfExists('betting_settings');
    }
};
