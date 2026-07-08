<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_fundings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->foreign('vendor_id')->references('id')->on('providers')->cascadeOnDelete();
            $table->unsignedBigInteger('payment_provider_id');
            $table->foreign('payment_provider_id')->references('id')->on('providers')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('reference')->unique();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->decimal('balance_before', 15, 2)->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_fundings');
    }
};
