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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id'); // or use $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->enum('transaction_type', [
                'airtime_recharge', 'data_subscription', 'cable_subscription', 'electric_bill', 'exam',
                'betting_funding', 'airtime_pin', 'data_pin', 'wallet_funding', 'manual_funding', 'bulksms'
            ]);

            $table->string('provider')->nullable();
            $table->string('account_or_phone')->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->double('quantity', 8, 2)->default(1.00);

            $table->enum('status', ['pending', 'success', 'fail']);
            $table->string('transaction_reference')->unique();
            $table->string('payment_reference')->nullable();

            $table->enum('funding_method', ['bank_transfer', 'credit_card', 'manual', 'other'])->nullable();
            $table->decimal('balance_before', 15, 2)->default(0.00);
            $table->decimal('balance_after', 15, 2)->default(0.00);

            $table->timestamp('completed_at')->nullable();
            $table->string('response_message')->nullable();
            $table->decimal('service_fee', 15, 2)->default(0.00);

            $table->string('platform')->nullable();
            $table->string('receiver')->nullable();
            $table->string('plan_type')->nullable();

            $table->string('token')->nullable();
            $table->timestamps(); // includes created_at and updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
