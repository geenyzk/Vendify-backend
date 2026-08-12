<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireStaleTransactions extends Command
{
    protected $signature = 'transactions:expire-stale
                            {--hours=24 : Age threshold in hours for pending transactions to expire}
                            {--dry-run : Log what would happen without mutating any transactions}';

    protected $description = 'Fail and refund stale pending transactions that have been unresolved for too long.';

    private const CHUNK_SIZE = 100;

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subHours($hours);

        $this->info("Expiring pending transactions older than {$hours} hour(s)");
        $expiredCount = 0;

        Transaction::where('status', 'pending')
            // VTU.ng has an authoritative requery endpoint and ambiguous
            // requests must never be assumed failed (or failed over/refunded)
            // solely because they are old.
            ->where(fn ($query) => $query->whereNull('provider')->orWhere('provider', '!=', 'vtu_ng'))
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($transactions) use (&$expiredCount, $dryRun, $hours, $cutoff) {
                foreach ($transactions as $transaction) {
                    if ($dryRun) {
                        $this->line(sprintf(
                            'DRY-RUN: would expire pending transaction %s (id=%d, user_id=%s, amount=%s, created_at=%s)',
                            $transaction->transaction_reference,
                            $transaction->id,
                            $transaction->user_id ?? 'null',
                            $transaction->amount,
                            $transaction->created_at?->toDateTimeString() ?? 'null'
                        ));
                        $expiredCount++;
                        continue;
                    }

                    $expired = DB::transaction(function () use ($transaction, $hours, $cutoff) {
                        $locked = Transaction::whereKey($transaction->id)
                            ->lockForUpdate()
                            ->first();

                        if (!$locked || $locked->status !== 'pending' || $locked->created_at->gte($cutoff)) {
                            return false;
                        }

                        $user = User::whereKey($locked->user_id)
                            ->lockForUpdate()
                            ->first();

                        $balanceAfter = $locked->balance_after;

                        if ($user
                            && in_array($locked->transaction_type, Transaction::REFUNDABLE_TYPES, true)
                            && (float) $locked->amount > 0
                        ) {
                            $user->increment('wallet_balance', (float) $locked->amount);
                            $balanceAfter = (float) $user->fresh()->wallet_balance;
                        }

                        $locked->update([
                            'status' => 'fail',
                            'response_message' => "Expired pending transaction after {$hours} hour(s).",
                            'balance_after' => $balanceAfter,
                        ]);

                        if (!$user) {
                            Log::error('transactions:expire-stale: pending transaction expired without a matching user', [
                                'transaction_id' => $locked->id,
                                'transaction_reference' => $locked->transaction_reference,
                            ]);
                        }

                        return true;
                    });

                    if ($expired) {
                        $expiredCount++;
                    }
                }
            });

        $message = $dryRun
            ? "DRY-RUN: {$expiredCount} pending transaction(s) would be expired."
            : "Expired {$expiredCount} pending transaction(s).";

        $this->info($message);

        return self::SUCCESS;
    }
}
