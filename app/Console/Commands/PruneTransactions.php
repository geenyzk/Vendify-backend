<?php

namespace App\Console\Commands;

use App\Classes\TransactionPruner;
use Illuminate\Console\Command;

class PruneTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete old success/fail transactions per the Settings > Transaction retention policy (no-ops if disabled).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = TransactionPruner::run();
        $this->info("Pruned {$count} transaction(s).");

        return self::SUCCESS;
    }
}
