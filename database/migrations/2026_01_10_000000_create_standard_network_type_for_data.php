<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // This migration's filename predates the migration that introduced
        // service_type. On a fresh install the definitive August migration
        // creates and links STANDARD after the schema is complete.
        if (! Schema::hasTable('network_types') || ! Schema::hasColumn('network_types', 'service_type')) {
            return;
        }
        // Create the STANDARD data type if it doesn't already exist.
        // This type is used for plans that don't fit into other customer-facing
        // categories or come from providers without meaningful type classification.
        DB::table('network_types')->updateOrInsert(
            [
                'name' => 'STANDARD',
                'service_type' => 'data',
            ],
            [
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the STANDARD type only if it has no associations.
        // Preserve it if it's in use to prevent data loss.
        $standardType = DB::table('network_types')
            ->where('name', 'STANDARD')
            ->where('service_type', 'data')
            ->first();

        if ($standardType) {
            $hasAssociations = Schema::hasTable('network_network_type') && DB::table('network_network_type')
                ->where('network_type_id', $standardType->id)
                ->exists();

            if (!$hasAssociations) {
                DB::table('network_types')
                    ->where('id', $standardType->id)
                    ->delete();
            }
        }
    }
};
