<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('providerables')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            if (!Schema::hasColumn('providerables', 'fallback_provider_id')) {
                $table->unsignedBigInteger('fallback_provider_id')->nullable()->after('provider_id');
                $table->foreign('fallback_provider_id')
                    ->references('id')->on('providers')->nullOnDelete();
            }

            if (!Schema::hasColumn('providerables', 'fallback_server_id')) {
                $table->string('fallback_server_id')->nullable()->after('server_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('providerables')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            if (Schema::hasColumn('providerables', 'fallback_provider_id')) {
                $table->dropForeign(['fallback_provider_id']);
                $table->dropColumn('fallback_provider_id');
            }
            if (Schema::hasColumn('providerables', 'fallback_server_id')) {
                $table->dropColumn('fallback_server_id');
            }
        });
    }
};
