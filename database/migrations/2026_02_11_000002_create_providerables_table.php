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
        

        Schema::create('providerables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('provider_id');
            $table->morphs('providerable'); // providerable_id, providerable_type
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->decimal('margin_value', 10, 2)->default(0);
            $table->string('margin_type')->default('fiat'); // 'fiat' or 'percentage'
            $table->timestamps();

            // avoid creating duplicate index name on some MySQL setups
            // morphs creates the columns; index creation is optional
            $table->foreign('provider_id')->references('id')->on('providers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('providerables');
    }
};
