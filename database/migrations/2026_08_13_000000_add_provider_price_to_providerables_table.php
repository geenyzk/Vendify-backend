<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('providerables') || Schema::hasColumn('providerables', 'provider_price')) {
            return;
        }

        Schema::table('providerables', function (Blueprint $table) {
            // The live amount reported by the upstream provider. cost_price
            // remains Vendify's independently editable role-pricing base.
            $table->decimal('provider_price', 12, 2)->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('providerables') && Schema::hasColumn('providerables', 'provider_price')) {
            Schema::table('providerables', fn (Blueprint $table) => $table->dropColumn('provider_price'));
        }
    }
};
