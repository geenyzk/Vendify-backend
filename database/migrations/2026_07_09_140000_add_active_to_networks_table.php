<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Networks had no active/status column, so the admin table always rendered
     * "pending" (StatusBadge's fallback for an unknown value) and the
     * activate/deactivate toggle couldn't persist. Add a real boolean the
     * Network model exposes as a "status" accessor. Existing rows default to
     * active so nothing silently disappears.
     */
    public function up(): void
    {
        if (Schema::hasColumn('networks', 'active')) {
            return;
        }

        Schema::table('networks', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('networks', 'active')) {
            return;
        }

        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
