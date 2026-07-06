<?php

namespace App\Classes;

use App\Mail\AdminNotificationMail;
use App\Models\AirtimeToCashRequest;
use App\Models\General;
use App\Models\Setting;
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
            $query = User::where('user_type', 'admin');
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
}
