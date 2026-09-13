<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SQLite must disable foreign keys outside a transaction while Laravel
    // rebuilds the enum table, or ON DELETE SET NULL can detach payout links.
    public $withinTransaction = false;

    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $alreadyWidened = $sqlite && str_contains(
            (string) DB::table('sqlite_master')->where('name', 'airtime_to_cash_requests')->value('sql'),
            "'failed'",
        );
        if (! $alreadyWidened) {
            $widen = fn () => Schema::table('airtime_to_cash_requests', function (Blueprint $table) {
                $table->enum('status', ['pending', 'approved', 'rejected', 'failed'])->default('pending')->change();
            });
            if ($sqlite) {
                Schema::withoutForeignKeyConstraints($widen);
            } else {
                $widen();
            }
        }

        // Only terminal, unpaid provider failures. Preserve references, reasons,
        // timestamps and all manual, ambiguous and confirmed requests.
        DB::table('airtime_to_cash_requests')
            ->where('processing_mode', 'provider')
            ->where('status', 'pending')->where('provider_status', 'failed')
            ->whereNull('provider_confirmed_at')->whereNull('payout_transaction_reference')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('transactions')
                    ->whereColumn('transactions.airtime_to_cash_request_id', 'airtime_to_cash_requests.id');
            })
            ->update(['status' => 'failed', 'active_session_key' => null, 'provider_identifier' => null]);
    }

    public function down(): void
    {
        // Retain terminal failure history; narrowing the enum would lose it.
    }
};
