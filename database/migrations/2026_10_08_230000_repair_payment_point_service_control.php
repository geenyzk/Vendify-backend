<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_controls')) {
            return;
        }

        $matching = DB::table('service_controls')
            ->where('category', 'transaction')
            ->where('sub_category', 'payment gateway')
            ->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", ['paymentpoint'])
            ->orderBy('id')
            ->get();

        if ($matching->isEmpty()) {
            DB::table('service_controls')->insert([
                'name' => 'payment point',
                'category' => 'transaction',
                'sub_category' => 'payment gateway',
                'isActive' => false,
                'isDevLock' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        // Preserve an administrator's enabled state if any historical spelling
        // was enabled, then collapse duplicates into one canonical row.
        $canonical = $matching->first();
        $enabled = $matching->contains(fn ($control) => (bool) $control->isActive);

        DB::table('service_controls')->where('id', $canonical->id)->update([
            'name' => 'payment point',
            'isActive' => $enabled,
            'isDevLock' => false,
            'updated_at' => now(),
        ]);

        DB::table('service_controls')
            ->whereIn('id', $matching->pluck('id')->reject(fn ($id) => $id === $canonical->id))
            ->delete();
    }

    public function down(): void
    {
        // Do not restore the broken dev-lock or recreate duplicate controls.
    }
};
