<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('severity')->default('warning'); // warning | critical
            $table->string('title', 500);
            // Issue text with numbers normalized out, so "3 stuck" and
            // "5 stuck" update one alert instead of stacking new ones.
            $table->string('fingerprint', 64)->index();
            $table->timestamp('acknowledged_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_alerts');
    }
};
