<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('data_plans', 'network_type_id')) {
            Schema::table('data_plans', function (Blueprint $table) {
                $table->foreignId('network_type_id')
                    ->nullable()
                    ->after('plan_type')
                    ->constrained('network_types')
                    ->nullOnDelete();
            });
        }

        $now = now();
        $standardId = DB::table('network_types')
            ->whereRaw('LOWER(name) = ?', ['standard'])
            ->whereRaw('LOWER(service_type) = ?', ['data'])
            ->value('id');

        if (! $standardId) {
            $standardId = DB::table('network_types')->insertGetId([
                'name' => 'STANDARD',
                'service_type' => 'data',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('network_types')->where('id', $standardId)->update([
                'name' => 'STANDARD',
                'service_type' => 'data',
                'active' => true,
                'updated_at' => $now,
            ]);
        }

        $providerTypeSql = "LOWER(REPLACE(REPLACE(REPLACE(TRIM(plan_type), '.', ''), ' ', ''), '_', '')) IN ('vtu', 'vtung')";
        $affectedNetworks = DB::table('data_plans')
            ->whereRaw($providerTypeSql)
            ->select('network')
            ->distinct()
            ->pluck('network');

        DB::table('data_plans')->whereRaw($providerTypeSql)->update([
            'plan_type' => 'STANDARD',
            'network_type_id' => $standardId,
            'updated_at' => $now,
        ]);

        // Link every other existing plan whose free-text type already matches a
        // managed Data type. This preserves old API contracts while making the
        // managed record authoritative for future reads and renames.
        DB::table('data_plans')
            ->whereNull('network_type_id')
            ->orderBy('id')
            ->chunkById(500, function ($plans) use ($now) {
                foreach ($plans as $plan) {
                    $typeId = DB::table('network_types')
                        ->whereRaw('LOWER(name) = ?', [strtolower(trim((string) $plan->plan_type))])
                        ->whereRaw('LOWER(service_type) = ?', ['data'])
                        ->value('id');

                    if ($typeId) {
                        DB::table('data_plans')->where('id', $plan->id)->update([
                            'network_type_id' => $typeId,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });

        // A managed type must be enabled for each carrier on which its plans
        // exist, otherwise /buy-data filters those plans out via the pivot.
        foreach ($affectedNetworks as $networkName) {
            $networkId = DB::table('networks')
                ->whereRaw('LOWER(name) = ?', [strtolower((string) $networkName)])
                ->value('id');

            if ($networkId) {
                DB::table('network_network_type')->updateOrInsert(
                    ['network_id' => $networkId, 'network_type_id' => $standardId],
                    ['service_type' => 'data', 'active' => true, 'created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('data_plans', 'network_type_id')) {
            Schema::table('data_plans', function (Blueprint $table) {
                $table->dropConstrainedForeignId('network_type_id');
            });
        }
    }
};
