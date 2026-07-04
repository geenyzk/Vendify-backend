<?php

namespace App\Jobs;

use App\Classes\Payment\PaymentFactory;
use App\Models\Provider;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorFunding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\BroadcastNotification;

class AutoFundVendor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly Vendor $vendor,
        public readonly float $currentBalance,
    ) {}

    public function handle(): void
    {
        $vendor = $this->vendor;

        // Guard: re-check that auto-fund is still enabled and fields are set
        if (
            !$vendor->auto_fund_enabled ||
            !$vendor->account_number ||
            !$vendor->bank_code ||
            !$vendor->funding_provider_id
        ) {
            Log::warning("AutoFundVendor: skipping [{$vendor->name}] — incomplete configuration.");
            return;
        }

        // Load the payment gateway provider
        $paymentProvider = Provider::find($vendor->funding_provider_id);
        if (!$paymentProvider) {
            Log::error("AutoFundVendor: funding_provider_id {$vendor->funding_provider_id} not found for vendor [{$vendor->name}].");
            return;
        }

        $gateway = PaymentFactory::make($paymentProvider);
        $reference = Transaction::generateTransactionId();
        $amount = (float) $vendor->auto_fund_amount;

        // Create an audit record in pending state before calling the gateway
        $funding = VendorFunding::create([
            'vendor_id'           => $vendor->id,
            'payment_provider_id' => $paymentProvider->id,
            'amount'              => $amount,
            'reference'           => $reference,
            'status'              => 'pending',
            'balance_before'      => $this->currentBalance,
        ]);

        try {
            $result = $gateway->transfer([
                'account_bank'   => $vendor->bank_code,
                'account_number' => $vendor->account_number,
                'amount'         => $amount,
                'narration'      => "Auto-fund: {$vendor->name}",
                'reference'      => $reference,
            ]);

            $status = $result['status'] === 'success' ? 'success' : 'failed';

            $funding->update([
                'status'           => $status,
                'gateway_response' => $result,
            ]);

            Log::info("AutoFundVendor: transfer {$status} for [{$vendor->name}]", [
                'amount'    => $amount,
                'reference' => $reference,
                'result'    => $result,
            ]);

        } catch (\Throwable $e) {
            $funding->update([
                'status'           => 'failed',
                'gateway_response' => ['error' => $e->getMessage()],
            ]);

            Log::error("AutoFundVendor: transfer threw exception for [{$vendor->name}]", [
                'error' => $e->getMessage(),
            ]);

            $this->notifyAdmins($vendor, $amount, 'failed', $e->getMessage());
            return;
        }

        $this->notifyAdmins($vendor, $amount, $funding->status);
    }

    private function notifyAdmins(Vendor $vendor, float $amount, string $status, string $note = ''): void
    {
        try {
            $admins = \App\Models\User::query()->where('user_type', 'admin')->get();
            $label  = $status === 'success' ? 'Successful' : 'Failed';
            $title  = "Vendor Auto-Fund {$label}";
            $body   = "N" . number_format($amount, 2) . " transfer to {$vendor->name} ({$vendor->account_number}) — {$status}.";
            if ($note) {
                $body .= " Reason: {$note}";
            }

            Notification::send($admins, new BroadcastNotification([
                'channels'     => ['database'],
                'emailSubject' => $title,
                'emailBody'    => $body,
                'notifTitle'   => $title,
                'notifMessage' => $body,
                'priorityHigh' => true,
            ]));
        } catch (\Throwable $e) {
            Log::warning("AutoFundVendor: failed to notify admins — " . $e->getMessage());
        }
    }
}
