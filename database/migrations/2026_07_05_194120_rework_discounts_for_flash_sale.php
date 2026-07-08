<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Discount becomes a flat-value flash-sale rule
     * (percentage or fixed) scoped to a service type and, optionally, one
     * network — replacing the old per-role-pricing / airtime-only model.
     * The old `type` column was really the service type and `name` was
     * really the network; both get copied into their properly-named new
     * columns before being dropped.
     */
    public function up(): void
    {
        // Schema drift (dump imports): these columns may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasColumn('discounts', 'service_type')) {
            return;
        }

        Schema::table('discounts', function (Blueprint $table) {
            $table->string('service_type')->nullable()->after('name');
            $table->string('network')->nullable()->after('service_type');
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage')->after('network');
            $table->decimal('value', 15, 2)->default(0)->after('discount_type');
        });

        // No historical per-role value can be losslessly collapsed into a
        // single flat value, so existing rows land at value=0 (inert) until
        // an admin re-configures them under the new model.
        DB::table('discounts')->get()->each(function ($row) {
            DB::table('discounts')->where('id', $row->id)->update([
                'service_type' => $row->type,
                'network' => $row->name,
            ]);
        });

        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn(['type', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->string('type')->nullable();
            $table->string('category')->nullable();
        });

        DB::table('discounts')->get()->each(function ($row) {
            DB::table('discounts')->where('id', $row->id)->update([
                'type' => $row->service_type,
            ]);
        });

        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn(['service_type', 'network', 'discount_type', 'value']);
        });
    }
};
