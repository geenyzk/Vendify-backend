<?php

namespace App\Classes;

use App\Mail\AdminNotificationMail;
use App\Models\General;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

    public static function notifySignup(User $user): void
    {
        $settings = Setting::first();
        if (!($settings?->notify_admin_on_signup ?? true)) {
            return;
        }
        self::send(
            'New user signup',
            "{$user->fullname} ({$user->email}, @{$user->username}) just created an account.",
        );
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
        }
    }
}
