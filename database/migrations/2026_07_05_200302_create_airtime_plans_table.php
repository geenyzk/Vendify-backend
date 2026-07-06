<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Split out of `discounts`, which is now dedicated to the separate
        // flash-sale Discount feature under Growth & Marketing — this table
        // keeps the original per-network airtime config (name = network,
        // category = network type, type is always "airtime") exactly as it
        // was, just under its own name so the two concepts stop colliding.
        Schema::create('airtime_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('type')->default('airtime');
            $table->decimal('min', 10, 2)->default(50);
            $table->decimal('max', 10, 2)->default(5000);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::table('discounts')->where('service_type', 'airtime')->get()->each(function ($row) {
            DB::table('airtime_plans')->insert([
                'name' => $row->network,
                'category' => null,
                'type' => 'airtime',
                'min' => $row->min,
                'max' => $row->max,
                'active' => $row->active,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        // Those rows now live in airtime_plans — remove them from discounts
        // so the new flash-sale feature starts from a clean slate.
        DB::table('discounts')->where('service_type', 'airtime')->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airtime_plans');
    }
};
