<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The welcome message no longer has a title — only the body is shown to
     * users. Make the legacy column nullable so records can be written
     * without one. (The controller also defaults it to '' as a safety net
     * for databases where this hasn't run yet.)
     */
    public function up(): void
    {
        if (Schema::hasColumn('welcome_messages', 'title')) {
            Schema::table('welcome_messages', function (Blueprint $table) {
                $table->string('title')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('welcome_messages', 'title')) {
            Schema::table('welcome_messages', function (Blueprint $table) {
                $table->string('title')->nullable(false)->default('')->change();
            });
        }
    }
};
