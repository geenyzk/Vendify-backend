<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Each step is guarded independently. MySQL DDL auto-commits and is not
        // rolled back when a later statement in the same migration throws, so a
        // first run that added these columns but then failed on the unique index
        // leaves the columns in place while the migration stays UNRECORDED. The
        // next run must therefore skip whatever already exists instead of dying
        // on "Duplicate column name" (1061) / "Duplicate key name" (1061), which
        // is exactly what produced the generic "database rejected that change".
        Schema::table('banks', function (Blueprint $table) {
            if (!Schema::hasColumn('banks', 'account_name')) {
                $table->string('account_name')->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('banks', 'currency')) {
                $table->string('currency', 3)->default('NGN')->after('provider');
            }
            if (!Schema::hasColumn('banks', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('status');
            }
        });

        if (!Schema::hasIndex('banks', 'banks_user_provider_unique')) {
            // A unique index cannot be created while duplicate (user_id, provider)
            // rows exist — that would abort with MySQL 1062 and, because 1062 on a
            // bare CREATE INDEX carries no row context we can safely act on, block
            // every future migration run. Deleting funding-account rows is not
            // something a schema migration should do unattended, so surface the
            // conflicts precisely and let an admin resolve them. (NULL providers
            // are ignored: MySQL permits multiple NULLs in a unique index.)
            $duplicates = DB::table('banks')
                ->select('user_id', 'provider', DB::raw('COUNT(*) as total'))
                ->whereNotNull('provider')
                ->groupBy('user_id', 'provider')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            if ($duplicates->isNotEmpty()) {
                $detail = $duplicates
                    ->map(fn ($row) => "user_id={$row->user_id}, provider={$row->provider} ({$row->total} rows)")
                    ->implode('; ');

                throw new RuntimeException(
                    'Cannot add banks_user_provider_unique: duplicate funding accounts exist. '
                    . 'Resolve these before re-running migrations — ' . $detail
                );
            }

            Schema::table('banks', function (Blueprint $table) {
                $table->unique(['user_id', 'provider'], 'banks_user_provider_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('banks', 'banks_user_provider_unique')) {
            Schema::table('banks', function (Blueprint $table) {
                $table->dropUnique('banks_user_provider_unique');
            });
        }

        Schema::table('banks', function (Blueprint $table) {
            foreach (['account_name', 'currency', 'failure_reason'] as $col) {
                if (Schema::hasColumn('banks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
