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
       Schema::create('discounts', function (Blueprint $table) {
    $table->id();
    $table->timestamps();
    $table->string('name');
    $table->enum('type', ['airtime','exam','airtimeToCash','user_upgrade','bulksms','airtimePin','electricity', 'airtime2cash'])->nullable();
    $table->string('category')->nullable();
    $table->decimal('user_discount', 10, 2);
    $table->decimal('bonanza_discount', 10, 2)->nullable();
    $table->decimal('agent_discount', 10, 2);
    $table->decimal('api_discount', 10, 2);
    $table->decimal('min', 10, 2)->default(50);
    $table->decimal('max', 10, 2)->default(5000);
    for ($i = 1; $i <= 5; $i++) {
        $table->string("adex_server_$i")->nullable();
        $table->string("spurs_server_$i")->nullable();
        $table->string("msorg_server_$i")->nullable();
    }
    $table->string('vtpass')->nullable();
    $table->string('payscribe')->nullable();
    $table->string('sme_plug')->nullable();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
