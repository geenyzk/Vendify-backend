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
        Schema::create('airtime_pin_plans', function (Blueprint $table) {
            $table->id();
            $table->string('network');
            $table->string('plan_type'); 
            $table->decimal('user_price', 10, 2)->default(0);
            $table->decimal('bonanza_price', 10, 2)->default(0);
            $table->decimal('agent_price', 10, 2)->default(0);
            $table->decimal('api_price', 10, 2)->default(0);
            for ($i = 1; $i <= 5; $i++) {
                $table->string("adex_server_$i")->default(0);
                $table->string("spurs_server_$i")->default(0);
                $table->string("msorg_server_$i")->default(0);
            }
            $table->string('vtpass')->default(0);
            $table->string('payscribe')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airtime_pin_plans');
    }
};
