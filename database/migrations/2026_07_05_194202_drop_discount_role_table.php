<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Per-role discount pricing is fully replaced by
     * Discount's own flat value/discount_type columns — see
     * rework_discounts_for_flash_sale — so this join table is dead.
     */
    public function up(): void
    {
        Schema::dropIfExists('discount_role');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('discount_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->decimal('discount', 5, 2)->default(0);
            $table->timestamps();
            $table->unique(['discount_id', 'role_id']);
        });
    }
};
