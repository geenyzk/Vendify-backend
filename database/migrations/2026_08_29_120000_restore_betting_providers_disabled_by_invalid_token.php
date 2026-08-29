<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // These providers were automatically disabled after VTU.ng returned
        // an account-wide invalid-token response on 2026-08-29. They are all
        // valid service IDs in the VTU.ng v2 betting documentation.
        DB::table('betting_providers')
            ->whereIn('biller_id', ['1xBet', 'Bet9ja', 'BetKing', 'SportyBet'])
            ->update(['active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Do not disable providers on rollback; their desired availability is
        // an administrator setting and cannot safely be inferred here.
    }
};
