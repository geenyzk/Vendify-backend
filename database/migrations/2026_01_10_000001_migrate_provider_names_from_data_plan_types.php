<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\DataPlan;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This data migration corrects plan types that were incorrectly set to provider
     * names (VTU.NG, etc.) to instead use proper customer-facing types.
     *
     * VTU.NG and similar provider-derived types should never appear in customer
     * purchase flows. Instead, plans are assigned meaningful types like STANDARD,
     * GIFTING, SME, etc., while the provider identity remains internal.
     */
    public function up(): void
    {
        // List of provider-derived plan types that should be migrated to STANDARD
        // instead of being shown to customers. Only applies to plans without other
        // meaningful type classifications.
        $providerDerivedTypes = ['vtu.ng', 'vtu', 'vtung'];

        // Update all plans with provider-derived types to use STANDARD.
        // These are internal-only provider names that must never be shown to customers.
        foreach ($providerDerivedTypes as $type) {
            DB::table('data_plans')
                ->whereRaw('LOWER(plan_type) = ?', [strtolower($type)])
                ->update(['plan_type' => DataPlan::STANDARD_TYPE]);
        }

        // Ensure STANDARD type exists in the database (should be created by migration)
        $standardTypeExists = DB::table('network_types')
            ->where('name', 'STANDARD')
            ->where('service_type', 'data')
            ->exists();

        if (!$standardTypeExists) {
            DB::table('network_types')->insert([
                'name' => 'STANDARD',
                'service_type' => 'data',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversing this migration would restore provider names as plan types,
        // which defeats the purpose of the fix. We intentionally don't reverse,
        // as the old state (exposing provider names) was incorrect.
        //
        // If you need to undo this, manually inspect data_plans and providerables
        // tables to understand which plans belonged to which providers.
    }
};
