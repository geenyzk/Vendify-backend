<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * user_discount/agent_discount/api_discount were NOT NULL with no
     * default, left over from the static per-user_type discount columns.
     * Pricing now comes from the discount_role table, so these are no
     * longer populated on create — without this, inserting a new discount
     * row fails with "Field 'user_discount' doesn't have a default value".
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE discounts MODIFY user_discount DECIMAL(10,2) NULL DEFAULT 0');
        DB::statement('ALTER TABLE discounts MODIFY agent_discount DECIMAL(10,2) NULL DEFAULT 0');
        DB::statement('ALTER TABLE discounts MODIFY api_discount DECIMAL(10,2) NULL DEFAULT 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE discounts MODIFY user_discount DECIMAL(10,2) NOT NULL');
        DB::statement('ALTER TABLE discounts MODIFY agent_discount DECIMAL(10,2) NOT NULL');
        DB::statement('ALTER TABLE discounts MODIFY api_discount DECIMAL(10,2) NOT NULL');
    }
};
