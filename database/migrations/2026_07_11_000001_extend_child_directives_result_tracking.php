<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 2 execution feedback: the child now acks a directive with the
        // real outcome (executed / failed / skipped + a note) instead of the
        // blanket 'delivered', so the admin UI can tell "applied" apart from
        // "child couldn't or doesn't know how to apply". 'delivered' remains
        // the value written for legacy children that ack with an empty body.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE child_directives MODIFY status ENUM('pending', 'delivered', 'executed', 'failed', 'skipped') NOT NULL DEFAULT 'pending'"
            );
        }

        Schema::table('child_directives', function (Blueprint $table) {
            if (!Schema::hasColumn('child_directives', 'result_note')) {
                $table->string('result_note', 1000)->nullable()->after('delivered_at');
            }
            if (!Schema::hasColumn('child_directives', 'executed_at')) {
                $table->timestamp('executed_at')->nullable()->after('delivered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('child_directives', function (Blueprint $table) {
            $table->dropColumn(['result_note', 'executed_at']);
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            // Collapse the new outcome values back into what the old enum allows
            // before narrowing it, or the ALTER fails on existing rows.
            DB::table('child_directives')->where('status', 'executed')->update(['status' => 'delivered']);
            DB::table('child_directives')->where('status', 'skipped')->update(['status' => 'failed']);
            DB::statement(
                "ALTER TABLE child_directives MODIFY status ENUM('pending', 'delivered', 'failed') NOT NULL DEFAULT 'pending'"
            );
        }
    }
};
