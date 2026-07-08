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
        Schema::create('airtime_pins', function (Blueprint $table) {
            $table->id();
            $table->enum("network", ["mtn", "glo", "airtel", "9mobile"]);
            $table->string("pin")->unique();
            $table->double("price")->default(0);
            $table->double("amount")->default(0);
            $table->enum("type", ["airtime", "data", "others"])->default("airtime");
            $table->enum("status", ["active", "used"])->default("active");
            $table->timestamps();
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airtime_pins');
    }
};
