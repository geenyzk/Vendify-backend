<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_transactions', function (Blueprint $table) {
            $table->timestamp('transacted_at')->nullable()->after('status');
            $table->index(
                ['child_instance_id', 'status', 'transacted_at'],
                'child_tx_activity_lookup'
            );
            $table->index(
                ['child_instance_id', 'child_customer_id', 'transacted_at'],
                'child_tx_customer_latest'
            );
        });

        // Preserve historical visibility using the original source payload.
        // Never fall back to the parent's created_at: that is sync time and is
        // not evidence that the customer purchased at that moment.
        DB::table('child_transactions')
            ->whereNull('transacted_at')
            ->orderBy('id')
            ->chunkById(500, function ($transactions) {
                foreach ($transactions as $transaction) {
                    $payload = json_decode($transaction->raw_payload ?? '{}', true) ?: [];
                    $sourceTime = $payload['transacted_at']
                        ?? $payload['created_at']
                        ?? $payload['date']
                        ?? null;

                    if (! $sourceTime) {
                        continue;
                    }

                    try {
                        DB::table('child_transactions')
                            ->where('id', $transaction->id)
                            ->update(['transacted_at' => Carbon::parse($sourceTime)]);
                    } catch (Throwable) {
                        // An invalid legacy date is safer left unknown than
                        // represented as recent customer activity.
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('child_transactions', function (Blueprint $table) {
            $table->dropIndex('child_tx_activity_lookup');
            $table->dropIndex('child_tx_customer_latest');
            $table->dropColumn('transacted_at');
        });
    }
};
