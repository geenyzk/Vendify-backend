<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Schema drift (dump imports): these columns may already exist without
        // this migration being recorded — skip instead of aborting the run.
        if (Schema::hasColumn('users', 'total_referral_earnings')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Lifetime total, unlike referral_balance which drains to zero
            // every time it's converted to wallet_balance — see
            // TransactionService::distributeCommission().
            $table->decimal('total_referral_earnings', 12, 2)->default(0.00);
        });

        // Backfill: referral_code was never actually generated anywhere
        // before this, so every existing user has a null one. Do this before
        // adding the unique index below (a unique index over all-null values
        // is fine, but we want every user usable as a referrer going forward).
        DB::table('users')->whereNull('referral_code')->orderBy('id')->get()->each(function ($user) {
            do {
                $code = strtoupper(Str::random(8));
            } while (DB::table('users')->where('referral_code', $code)->exists());

            DB::table('users')->where('id', $user->id)->update(['referral_code' => $code]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('referral_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('total_referral_earnings');
        });
    }
};
