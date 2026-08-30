<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('generals') || ! Schema::hasColumn('generals', 'app_email')) {
            return;
        }

        DB::table('generals')
            ->where('id', 1)
            ->update([
                'app_email' => 'info@geeny.co.uk',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // This is a privacy/contact-data correction and must not restore the
        // removed personal address during a rollback.
    }
};
