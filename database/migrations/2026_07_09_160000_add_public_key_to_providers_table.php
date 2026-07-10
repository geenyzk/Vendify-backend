<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * vtpass authenticates with an api-key + public-key pair (see
     * Providers\vtpass::getAuthHeaders). Only api_key had a column, so vtpass
     * could never be configured through the provider form — add public_key.
     */
    public function up(): void
    {
        if (Schema::hasColumn('providers', 'public_key')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            $table->string('public_key')->nullable()->after('api_key');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('providers', 'public_key')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('public_key');
        });
    }
};
