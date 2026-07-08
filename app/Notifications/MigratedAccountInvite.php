<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a child affiliate's customer right after migration creates their
 * brand-new parent account (never when they were linked to one they already
 * had). Uses a real password-broker token so "Set your password" is the
 * account-claim step — the random password the account was created with is
 * never sent to anyone.
 */
class MigratedAccountInvite extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $token,
        protected string $childName,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name');
        // Same URL shape as ResetPassword::createUrlUsing in AppServiceProvider.
        $url = config('app.frontend_url')
            . '/auth/reset-password?token=' . $this->token
            . '&email=' . urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject("Your {$appName} account is ready")
            ->greeting("Hello {$notifiable->username},")
            ->line("{$this->childName} is moving its customers to {$appName}, and an account has been created for you.")
            ->line('Set your password to claim it — your username stays the same.')
            ->action('Set your password', $url)
            ->line('If you were not expecting this, you can ignore this email.');
    }
}
