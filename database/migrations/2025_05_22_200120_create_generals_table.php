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
        Schema::create('generals', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('app_name')->default('Laravel');
            $table->string('app_phone')->default('#');
            $table->string('app_address')->default('#');
            $table->string('app_email')->default('#');
            $table->string('bvn')->default('#');
            $table->string('bankName')->default('#');
            $table->string('accountName')->default('#');
            $table->string('accountNumber')->default('#');
            $table->string('logo')->default('#');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generals');
    }
};
