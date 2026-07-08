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
        Schema::table('generals', function (Blueprint $table) {
            // Browser tab title / SEO description — deliberately separate
            // from app_name since a page title often reads better as a
            // fuller tagline ("Vendify — Airtime, Data & Bills") than the bare
            // brand name shown in headers/sidebars.
            $table->string('meta_title')->nullable()->after('app_name');
            $table->text('meta_description')->nullable()->after('meta_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generals', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description']);
        });
    }
};
