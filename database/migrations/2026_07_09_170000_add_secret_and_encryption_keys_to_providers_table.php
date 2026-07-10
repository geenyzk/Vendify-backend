<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flutterwave v3 authenticates with a public key + secret key + encryption
     * key. Only api_key/public_key had columns (Provider even listed secret_key
     * as fillable with no column behind it, so it silently never saved) — add
     * the missing secret_key and encryption_key.
     */
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            if (!Schema::hasColumn('providers', 'secret_key')) {
                $table->string('secret_key')->nullable()->after('api_key');
            }
            if (!Schema::hasColumn('providers', 'encryption_key')) {
                $table->string('encryption_key')->nullable()->after('secret_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            foreach (['secret_key', 'encryption_key'] as $col) {
                if (Schema::hasColumn('providers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
