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
        // Schema drift (dump imports): these columns may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasColumn('roles', 'upgradable')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            // Whether a customer can self-upgrade into this role from
            // /upgrade-account, and what it costs — replaces the old
            // Discount(service_type=user_upgrade) hack, which duplicated
            // tier names as free text disconnected from the real Role rows.
            $table->boolean('upgradable')->default(false)->after('is_active');
            $table->decimal('upgrade_cost', 12, 2)->nullable()->after('upgradable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['upgradable', 'upgrade_cost']);
        });
    }
};
