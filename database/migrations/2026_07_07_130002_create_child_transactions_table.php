<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_customer_id')->nullable()->constrained('child_customers')->nullOnDelete();
            // The child's own transaction reference (e.g. its `data.transid`).
            $table->string('external_id');
            $table->string('transaction_type')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('status')->nullable();
            // Anything not mapped to a column above — kept for visibility/
            // debugging without forcing a schema change per child app.
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['child_instance_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_transactions');
    }
};
