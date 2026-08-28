<?php

namespace App\Classes;

use App\Mail\AdminNotificationMail;
use App\Models\AiAlert;
use App\Models\AirtimeToCashRequest;
use App\Models\General;
use App\Models\Setting;
use App\Models\Sim;
use App\Models\SimVendJob;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletWithdrawal;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

// Every public method here is fire-and-forget: a failed notification (bad
// SMTP config, no admin email set, etc.) must never break the real operation
// it's attached to, so every send is wrapped and only ever logged on failure.
class AdminNotifier
{
    protected static function send(string $subject, string $body): void
    {
        try {
            $adminEmail = General::first()?->app_email;
            if (!$adminEmail) {
                return;
            }
            Mail::to($adminEmail)->send(new AdminNotificationMail($subject, $body));
        } catch (\Throwable $e) {
            Log::warning('AdminNotifier: failed to send notification', [
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * In-app bell notification for every admin user (optionally narrowed to
     * those whose role carries a specific permission — e.g. only admins who
     * can actually review airtime-to-cash get pinged about a new one).
     * Separate audience from send() above: send() emails one configured
     * contact address, this reaches every real admin *account*.
     */
    protected static function notifyAdminUsers(string $type, string $title, string $body, ?string $permission = null): void
    {
        try {
            $query = User::whereHas('role', fn ($role) => $role->where('is_staff', true)->where('is_active', true));
            if ($permission) {
                $query->whereHas('role.permissions', fn ($q) => $q->where('slug', $permission));
            }
            $admins = $query->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new AppNotification($type, $title, $body));
            }
        } catch (\Throwable $e) {
            Log::warning('AdminNotifier: failed to create in-app notification', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The AI monitor detected a critical platform problem (vendor down, all
     * SIM devices offline, ...). Called once per alert, when it is first
     * created — re-detections update the existing alert without re-mailing.
     */
    public static function notifyAiAlert(AiAlert $alert): void
    {
        self::send('AI monitor: critical alert', $alert->title);
        self::notifyAdminUsers('admin_ai_alert', 'AI monitor alert', $alert->title, 'ai_manager');
    }

    public static function notifySignup(User $user): void
    {
        $settings = Setting::first();
        if ($settings?->notify_admin_on_signup ?? true) {
            self::send(
                'New user signup',
                "{$user->fullname} ({$user->email}, @{$user->username}) just created an account.",
            );
        }
        self::notifyAdminUsers('admin_new_signup', 'New user signup', "@{$user->username} just created an account.");
    }

    public static function notifyFunding(Transaction $transaction): void
    {
        $settings = Setting::first();
        if ($settings?->notify_admin_on_funding ?? true) {
            self::send(
                'Wallet funded',
                "User #{$transaction->user_id} funded their wallet with ₦{$transaction->amount} via {$transaction->provider}. Reference: {$transaction->transaction_reference}.",
            );
        }
        self::notifyAdminUsers(
            'admin_wallet_funding',
            'Wallet funded',
            "User #{$transaction->user_id} funded their wallet with ₦{$transaction->amount} via {$transaction->provider}.",
        );
        self::maybeNotifyLargeTransaction($transaction, $settings);
    }

    public static function notifyTransaction(Transaction $transaction): void
    {
        $settings = Setting::first();

        if ($transaction->status === 'fail' && ($settings?->notify_admin_on_failed_transaction ?? false)) {
            self::send(
                'Transaction failed',
                "Transaction {$transaction->transaction_reference} for user #{$transaction->user_id} failed. " .
                    ($transaction->response_message ?? 'No response message.'),
            );
            self::notifyAdminUsers(
                'admin_failed_transaction',
                'Transaction failed',
                "Transaction {$transaction->transaction_reference} for user #{$transaction->user_id} failed.",
            );
        }

        self::maybeNotifyLargeTransaction($transaction, $settings);
    }

    protected static function maybeNotifyLargeTransaction(Transaction $transaction, ?Setting $settings): void
    {
        $threshold = floatval($settings?->large_transaction_threshold ?? 50000);
        $enabled = $settings?->notify_admin_on_large_transaction ?? true;

        if ($enabled && $transaction->status === 'success' && floatval($transaction->amount) >= $threshold) {
            self::send(
                'Large transaction alert',
                "Transaction {$transaction->transaction_reference} for user #{$transaction->user_id} was ₦{$transaction->amount} (threshold ₦{$threshold}).",
            );
            self::notifyAdminUsers(
                'admin_large_transaction',
                'Large transaction alert',
                "Transaction {$transaction->transaction_reference} for user #{$transaction->user_id} was ₦{$transaction->amount}.",
            );
        }
    }

    /**
     * A customer just submitted an airtime-to-cash request — only relevant
     * to admins who hold the `airtime_to_cash` permission (see
     * AirtimeToCashController).
     */
    public static function notifyAirtimeToCashPending(AirtimeToCashRequest $request): void
    {
        $settings = Setting::first();
        $body = "New airtime-to-cash request: ₦{$request->amount} ({$request->network}) awaiting review.";

        if ($settings?->notify_admin_on_airtime_to_cash ?? true) {
            self::send('New airtime-to-cash request', $body);
        }
        self::notifyAdminUsers('admin_pending_airtime_to_cash', 'New airtime-to-cash request', $body, 'airtime_to_cash');
    }

    /**
     * A customer just submitted a wallet withdrawal that needs manual
     * approval (i.e. Setting::wallet_withdrawal_auto_approve is off) —
     * only relevant to admins who hold the `wallets` permission.
     */
    public static function notifyWalletWithdrawalPending(WalletWithdrawal $withdrawal): void
    {
        $settings = Setting::first();
        $body = "New wallet withdrawal request: ₦{$withdrawal->amount} to {$withdrawal->bank_name} awaiting review.";

        if ($settings?->notify_admin_on_wallet_withdrawal ?? true) {
            self::send('New wallet withdrawal request', $body);
        }
        self::notifyAdminUsers('admin_pending_wallet_withdrawal', 'New wallet withdrawal request', $body, 'wallets');
    }

    /**
     * A vending SIM's reported stock dropped below its threshold — routing
     * is (or is about to start) skipping it, so it needs a physical top-up.
     * Rate-limited by the caller (SimDeviceController::maybeAlertLowBalance).
     */
    public static function notifySimLowBalance(Sim $sim): void
    {
        $body = "SIM #{$sim->id} ({$sim->network}, slot {$sim->slot_index}, device #{$sim->sim_device_id}) is low on stock: "
            . "airtime ₦{$sim->airtime_balance}, data {$sim->data_balance_mb}MB. Top it up so SIM vending keeps serving {$sim->network}.";

        self::send('SIM vending: low SIM balance', $body);
        self::notifyAdminUsers('admin_sim_low_balance', 'SIM vending: low SIM balance', $body);
    }

    /**
     * A claimed vend job's lease expired without an ack, so the customer was
     * refunded — but the device may have delivered the value before dying.
     * Someone must reconcile against the SIM's transfer history.
     */
    public static function notifySimJobExpired(SimVendJob $job): void
    {
        $body = "SIM vend job #{$job->id} ({$job->service} ₦{$job->amount} to {$job->phone}, ref {$job->transaction_reference}) "
            . "expired without an ack from device #{$job->sim_device_id}. The customer was refunded — check the SIM's transfer "
            . "history to confirm nothing was actually delivered.";

        self::send('SIM vending: job expired, refunded', $body);
        self::notifyAdminUsers('admin_sim_job_expired', 'SIM vending: job expired, refunded', $body);
    }

    /**
     * A device acked "executed" on a job that had already been settled as
     * failed (customer refunded). Value likely left the SIM AND the wallet
     * was refunded — manual reconciliation required. No money is ever moved
     * automatically for this case.
     */
    public static function notifySimVendDiscrepancy(SimVendJob $job): void
    {
        $body = "Device #{$job->sim_device_id} reported job #{$job->id} (ref {$job->transaction_reference}, "
            . "{$job->service} ₦{$job->amount} to {$job->phone}) as DELIVERED after it was already refunded. "
            . "Reconcile manually — the customer may have received both the value and the refund.";

        self::send('SIM vending: delivery/refund discrepancy', $body);
        self::notifyAdminUsers('admin_sim_vend_discrepancy', 'SIM vending: delivery/refund discrepancy', $body);
    }
}
