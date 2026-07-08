<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Schema drift (dump imports): these columns may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasColumn('data_plans', 'is_draft')) {
            return;
        }

        Schema::table('data_plans', function (Blueprint $table) {
            // Distinguishes a plan auto-created by a vendor plan sync (e.g.
            // Ogdams::syncPlans()) and still awaiting admin review/pricing
            // from one an admin deliberately deactivated — both currently
            // read as "inactive" via the `active` column alone, which isn't
            // enough to tell the two apart in the admin UI.
            $table->boolean('is_draft')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('data_plans', function (Blueprint $table) {
            $table->dropColumn('is_draft');
        });
    }
};
