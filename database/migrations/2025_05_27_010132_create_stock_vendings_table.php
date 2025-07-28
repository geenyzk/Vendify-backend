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
        Schema::create('stock_vendings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->string('sns')->default('adex_server_1');
            $table->string('vtu')->default('adex_server_1');
            $table->string('sme')->default('adex_server_1');
            $table->string('gifting')->default('adex_server_1');
            $table->string('cooperate gifting')->default('adex_server_1');
            $table->string('dstv')->default('adex_server_1');
            $table->string('gotv')->default('adex_server_1');
            $table->string('startimes')->default('adex_server_1');
            $table->string('data pin')->default('adex_server_1');
            $table->string('recharge pin')->default('adex_server_1');
            $table->string('exam')->default('adex_server_1');
            $table->string('electricity')->default('adex_server_1');
            $table->string('bulksms')->default('adex_server_1');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_vendings');
    }
};
