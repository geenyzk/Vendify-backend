<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_instance_id')->constrained()->cascadeOnDelete();
            // The child's own primary key for this user (e.g. its `user.id`)
            // — not globally unique on its own, only unique per instance.
            $table->string('external_id');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('wallet_balance', 15, 2)->default(0);
            $table->string('status')->nullable();
            // Populated only by the explicit Phase 2 "promote to real
            // account" admin action — never written by passive sync.
            $table->foreignId('migrated_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['child_instance_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_customers');
    }
};
