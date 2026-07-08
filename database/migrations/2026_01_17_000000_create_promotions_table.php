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
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('name');
            $table->string('code')->nullable();
            $table->enum('apply', ['auto', 'code'])->default('code');
            $table->enum('target', ['customer', 'reseller', 'both'])->default('customer');
            $table->enum('product', ['airtime', 'data', 'bundle'])->default('airtime');
            $table->string('provider')->nullable();

            // benefit type: percentage, fixed amount, bonus data or cashback
            $table->enum('type', ['percentage', 'fixed', 'bonus_data', 'cashback'])->default('percentage');
            $table->decimal('value', 15, 2)->default(0);

            $table->boolean('active')->default(true);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();

            $table->unsignedBigInteger('usage_limit_total')->nullable();
            $table->unsignedBigInteger('usage_limit_per_customer')->nullable();
            $table->unsignedBigInteger('used')->default(0);

            $table->json('conditions')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
