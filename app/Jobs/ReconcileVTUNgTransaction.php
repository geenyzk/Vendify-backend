<?php

namespace App\Jobs;

use App\Classes\Vendor\Providers\VTUNg;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileVTUNgTransaction implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public int $transactionId)
    {
    }

    public function backoff(): array
    {
        return [20, 30, 60, 120, 300, 300, 300];
    }

    public function handle(): void
    {
        $transaction = Transaction::find($this->transactionId);
        if (! $transaction || $transaction->provider !== 'vtu_ng' || $transaction->status !== 'pending') {
            return;
        }

        $vendor = Vendor::where('sub_category', 'vtu_ng')->where('active', true)->first();
        if (! $vendor) {
            $this->release($this->backoff()[min($this->attempts() - 1, 6)]);
            return;
        }

        if (! (new VTUNg($vendor))->reconcile($transaction)) {
            $this->release($this->backoff()[min($this->attempts() - 1, 6)]);
        }
    }
}
