<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('providerables') || Schema::hasColumn('providerables', 'fallbacks')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            $table->json('fallbacks')->nullable()->after('fallback_cost_price');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('providerables') || ! Schema::hasColumn('providerables', 'fallbacks')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            $table->dropColumn('fallbacks');
        });
    }
};
