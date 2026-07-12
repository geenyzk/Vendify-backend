<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Schema drift (dump imports): the column may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasColumn('data_plans', 'sort_order')) {
            return;
        }

        Schema::table('data_plans', function (Blueprint $table) {
            // Admin-controlled display order (see AdminController::reorder and
            // DataPlanResource). The column was referenced throughout the app
            // (DataPlan::$fillable, Ogdams::syncPlans) but never migrated.
            $table->unsignedInteger('sort_order')->default(0)->after('is_draft');
        });
    }

    public function down(): void
    {
        Schema::table('data_plans', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
