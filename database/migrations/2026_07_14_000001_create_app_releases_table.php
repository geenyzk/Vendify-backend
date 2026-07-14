<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_releases')) {
            Schema::create('app_releases', function (Blueprint $table) {
                $table->id();
                // Human-facing version ("1.2.0") and the monotonically
                // increasing integer the mobile app compares against to decide
                // whether a newer build is available.
                $table->string('version_name');
                $table->unsignedInteger('version_code');
                $table->string('platform')->default('android');
                $table->text('notes')->nullable();
                // Where the binary lives on the configured disk, plus metadata
                // used to serve the download with correct headers.
                $table->string('file_path');
                $table->string('file_name');
                $table->unsignedBigInteger('size')->default(0);
                $table->string('mime')->nullable();
                // Exactly one release per platform is the "published/latest"
                // one clients receive. Older rows stay for history/rollback.
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('downloads')->default(0);
                $table->timestamps();

                $table->index(['platform', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
