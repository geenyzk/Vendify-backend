<?php

namespace App\Console\Commands;

use App\Classes\Payment\PaymentFactory;
use App\Classes\Payment\Provider\FlutterWave;
use App\Models\Provider;
use App\Models\WalletWithdrawal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileFlutterwaveWithdrawals extends Command
{
    protected $signature = 'flutterwave:reconcile-withdrawals {--limit=100}';

    protected $description = 'Verify non-terminal Flutterwave withdrawals and settle them idempotently';

    public function handle(): int
    {
        // Reconciliation must still run after an admin disables new payouts.
        $provider = Provider::where('category', 'payment')
            ->whereRaw('LOWER(name) = ?', ['flutterwave'])
            ->first();
        if (!$provider || !$provider->secret_key) {
            $this->warn('Flutterwave is not configured.');
            return self::SUCCESS;
        }

        $gateway = PaymentFactory::make($provider);
        if (!$gateway instanceof FlutterWave) {
            return self::FAILURE;
        }

        WalletWithdrawal::whereIn('status', [
            WalletWithdrawal::STATUS_PENDING,
            WalletWithdrawal::STATUS_PROCESSING,
        ])->oldest()->limit((int) $this->option('limit'))->get()->each(
            function (WalletWithdrawal $withdrawal) use ($gateway) {
                try {
                    $verified = $withdrawal->gateway_transfer_id
                        ? $gateway->verifyTransfer($withdrawal->gateway_transfer_id)
                        : $gateway->findTransferByReference($withdrawal->transaction_reference);

                    if ($verified) {
                        $gateway->settleWithdrawal($verified);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Flutterwave withdrawal reconciliation deferred.', [
                        'withdrawal_id' => $withdrawal->id,
                        'reference' => $withdrawal->transaction_reference,
                        'error_category' => class_basename($e),
                    ]);
                }
            }
        );

        return self::SUCCESS;
    }
}
