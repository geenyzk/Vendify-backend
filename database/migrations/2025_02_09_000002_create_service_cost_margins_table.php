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
        Schema::create('service_cost_margins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->string('service_type'); // e.g., 'data', 'airtime', 'cable', 'electricity', etc.
            $table->string('margin_type')->default('fiat');
            $table->decimal('cost_price', 12, 2);
            $table->decimal('margin_profit', 12, 2);
            $table->decimal('selling_price', 12, 2)->virtualAs('cost_price + margin_profit');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Unique constraint on role and service type
            $table->unique(['role_id', 'service_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_cost_margins');
    }
};
