<?php

use App\Models\AirtimePlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('transactions', 'network')) $table->string('network')->nullable()->index();
                if (! Schema::hasColumn('transactions', 'airtime_plan_id')) $table->unsignedBigInteger('airtime_plan_id')->nullable()->index();
                if (! Schema::hasColumn('transactions', 'primary_provider_id')) $table->unsignedBigInteger('primary_provider_id')->nullable()->index();
                if (! Schema::hasColumn('transactions', 'final_provider_id')) $table->unsignedBigInteger('final_provider_id')->nullable()->index();
                if (! Schema::hasColumn('transactions', 'fallback_used')) $table->boolean('fallback_used')->default(false)->index();
            });
        }

        if (! Schema::hasTable('airtime_plans') || ! Schema::hasTable('providerables') || ! Schema::hasTable('service_routes')) return;

        // Preserve every explicit plan assignment. Only plans with no primary
        // receive their previously effective category-level airtime route.
        foreach (DB::table('airtime_plans')->get() as $plan) {
            $hasPrimary = DB::table('providerables')
                ->where('providerable_id', $plan->id)
                ->where('providerable_type', AirtimePlan::class)
                ->whereNotNull('provider_id')->exists();
            if ($hasPrimary) continue;

            $key = strtolower(str_replace(['_', '-'], ' ', trim((string) ($plan->category ?: 'vtu'))));
            $route = DB::table('service_routes')->whereNotNull('provider_id')->get()->first(
                fn ($row) => strtolower(trim((string) $row->service_type)) === 'airtime'
                    && strtolower(str_replace(['_', '-'], ' ', trim((string) $row->route_key))) === $key
            );
            if (! $route) continue;

            $existing = DB::table('providerables')->where('providerable_id', $plan->id)
                ->where('providerable_type', AirtimePlan::class)->first();
            if ($existing) {
                DB::table('providerables')->where('id', $existing->id)->update(['provider_id' => $route->provider_id, 'updated_at' => now()]);
            } else {
                DB::table('providerables')->insert([
                    'provider_id' => $route->provider_id, 'providerable_id' => $plan->id,
                    'providerable_type' => AirtimePlan::class, 'cost_price' => 0,
                    'margin_value' => 0, 'margin_type' => 'fiat', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Old airtime rows are retained for audit/rollback, but application
        // code and admin APIs no longer read or mutate them.
    }

    public function down(): void
    {
        // Routing rows are intentionally not reverted: they may have been
        // edited after migration. Trace columns are safe to leave in place.
    }
};
