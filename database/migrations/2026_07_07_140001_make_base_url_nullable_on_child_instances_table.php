<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The self-registration flow only ever provides `name` up front —
        // base_url is optional/informational (nothing in Phase 1 actually
        // calls out to the child), so it can no longer be required at
        // creation time.
        DB::statement('ALTER TABLE child_instances MODIFY base_url VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE child_instances MODIFY base_url VARCHAR(255) NOT NULL');
    }
};
