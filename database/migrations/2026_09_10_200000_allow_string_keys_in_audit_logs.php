<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasColumn('audit_logs', 'auditable_id')) {
            return;
        }

        // Auditable models may use natural string keys, such as the provider
        // key on AirtimeToCashProviderSetting. A numeric morph key rejects
        // those otherwise valid audit records in MySQL.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('auditable_id', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        // String model keys cannot be converted back to integers without
        // destroying audit history, so this corrective schema change stays.
    }
};
