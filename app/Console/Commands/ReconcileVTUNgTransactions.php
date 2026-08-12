<?php

namespace App\Console\Commands;

use App\Classes\Vendor\Providers\VTUNg;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileVTUNgTransactions extends Command
{
    protected $signature = 'vtu-ng:reconcile {--limit=100}';
    protected $description = 'Requery pending VTU.ng orders and settle confirmed outcomes';

    public function handle(): int
    {
        $vendor = Vendor::where('sub_category', 'vtu_ng')->where('active', true)->first();
        if (! $vendor) {
            return self::SUCCESS;
        }

        $client = new VTUNg($vendor);
        $settled = 0;
        Transaction::query()
            ->where('provider', 'vtu_ng')
            ->where('status', 'pending')
            ->oldest()
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->get()
            ->each(function (Transaction $transaction) use ($client, &$settled) {
                try {
                    if ($client->reconcile($transaction)) {
                        $settled++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('VTU.ng reconciliation failed', [
                        'transaction_id' => $transaction->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        $this->info("Settled {$settled} VTU.ng transaction(s).");
        return self::SUCCESS;
    }
}
