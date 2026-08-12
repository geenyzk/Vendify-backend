<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 16);
            $table->timestamp('last_used_at', 6)->index();
            $table->timestamps();
            $table->unique(['user_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_recipients');
    }
};
