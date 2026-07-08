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
        // Tracks which users have seen which welcome message. `seen_at` is compared
        // against `welcome_messages.updated_at` at read time so editing the message
        // (title/body) makes it pop up again for everyone, without needing to
        // recreate the row or version its id.
        Schema::create('welcome_message_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('welcome_message_id')->constrained()->onDelete('cascade');
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['user_id', 'welcome_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('welcome_message_views');
    }
};
