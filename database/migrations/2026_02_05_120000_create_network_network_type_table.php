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
        Schema::create('network_network_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->nullable()->constrained('networks')->onDelete('cascade');
            $table->foreignId('network_type_id')->constrained('network_types')->onDelete('cascade');
            $table->string("service_type");
            $table->timestamps();

            $table->unique(['network_id', 'network_type_id'], 'network_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('network_network_type');
    }
};
