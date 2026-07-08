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
        Schema::create('bill_plans', function (Blueprint $table) {
            $table->id();
            // Matches disco_provider_ids.name (e.g. "Ikeja Electric") — the
            // same free-text disco identity already used to look up each
            // vendor's own internal ID for that disco.
            $table->string('disco');
            // Same shape as AirtimePlan: a per-disco amount range, not a
            // fixed catalog price — electricity tokens are pay-any-amount.
            $table->decimal('min', 10, 2)->default(500);
            $table->decimal('max', 10, 2)->default(100000);
            // Role-keyed {type, value} entries, additive on top of whatever
            // amount the customer chooses — mirrors CablePlan::charge_fee,
            // except computed over a variable amount rather than a fixed cost.
            $table->json('service_fee')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_plans');
    }
};
