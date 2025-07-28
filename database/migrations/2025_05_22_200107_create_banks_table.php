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
        Schema::create('banks', function (Blueprint $table) {
            $table->id(); // id as BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
            $table->timestamps(); // created_at and updated_at
            
            $table->timestamp('expired_at')->default('2025-03-17 05:34:35');

            $table->string('user_id'); // Consider using ->foreignId() if it's referencing users.id
            $table->string('account_type')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('provider')->nullable();

            $table->enum('status', ['active', 'inactive'])->nullable();

            $table->decimal('amount', 8, 2)->default(0.00);

            $table->string('ref')->nullable();
            $table->string('tx_ref')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
