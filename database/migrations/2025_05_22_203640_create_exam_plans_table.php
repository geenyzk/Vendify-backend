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
        Schema::create('exam_plans', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->decimal('user_discount', 10, 2);
            $table->decimal('bonanza_discount', 10, 2)->nullable();
            $table->decimal('agent_discount', 10, 2);
            $table->decimal('api_discount', 10, 2);
            for ($i = 1; $i <= 5; $i++) {
                $table->string("adex_server_$i")->nullable();
                $table->string("spurs_server_$i")->nullable();
                $table->string("msorg_server_$i")->nullable();
            }
            $table->string('vtpass')->nullable();
            $table->string('payscribe')->nullable();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_plans');
    }
};
