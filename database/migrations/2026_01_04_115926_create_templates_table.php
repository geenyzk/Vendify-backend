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
        Schema::create('templates', function (Blueprint $table) {
            // Fresh installs should match the model immediately. The later
            // repair migration remains necessary for databases that already
            // ran the original version without these columns.
            $table->id();
            $table->string('name', 191)->comment('Human friendly name for admin UI');
            $table->string('slug', 191)->unique()->comment('Unique slug derived from name');

            // Type + event (event-specific templates)
            $table->enum('type', ['event', 'broadcast'])->default('event')->index();
            $table->enum('event', ['login', 'register', 'purchase', 'wallet_credit', 'wallet_debit'])->nullable()->index();

            // Messaging fields
            $table->string('subject')->nullable()->comment('Email subject or notification headline (optional for broadcast)');
            $table->text('content')->comment('Template body; use variables like {{name}}, {{amount}}');

            // Channels stored as json array: ["email","sms","in_app","push"]
            $table->json('channels')->nullable()->comment('Delivery channels for this template');

            // Flags & audit
            $table->boolean('enabled')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};


// // app/Models/Template.php
// <?php

// namespace App\Models;

// use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Database\Eloquent\Model;
// use Illuminate\Database\Eloquent\SoftDeletes;
// use Illuminate\Support\Str;

// class Template extends Model
