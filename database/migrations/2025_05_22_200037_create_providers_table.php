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
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('base_url')->nullable();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('charge_fee')->nullable();
            $table->string('charge_type')->nullable();
            $table->boolean('active')->default(false);
            $table->string('api_key')->nullable();
            $table->enum('auth_type', ['bearer', 'token', 'basic'])->nullable();
            $table->string('webhook_access')->default('1');
            $table->string('identifier')->nullable();
            $table->enum('category', ['vendor', 'payment'])->nullable();
            $table->enum('sub_category', ['adex', 'spurs', 'msorg', 'simhost', 'misc', 'payment'])->nullable();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
