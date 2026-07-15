<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs the `templates` table, which never matched its model.
 *
 * The create migration omitted `$table->id()` entirely, so the table had no
 * primary key and no id — while App\Models\Template (and TemplateController's
 * show/update/destroy routes) address rows by id. It also `use`s SoftDeletes
 * without a `deleted_at` column, which made Eloquent add
 * "where templates.deleted_at is null" to every query. The net effect: any
 * Template query threw "Unknown column 'templates.deleted_at'", so the whole
 * notification-templates feature was dead on arrival.
 *
 * Adding an auto-increment primary key is safe here because the table has no
 * rows to renumber.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('templates')) {
            return;
        }

        Schema::table('templates', function (Blueprint $table) {
            if (!Schema::hasColumn('templates', 'id')) {
                $table->id()->first();
            }

            if (!Schema::hasColumn('templates', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('templates')) {
            return;
        }

        Schema::table('templates', function (Blueprint $table) {
            if (Schema::hasColumn('templates', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('templates', 'id')) {
                $table->dropColumn('id');
            }
        });
    }
};
