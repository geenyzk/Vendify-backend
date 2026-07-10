<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VendifyVerifyEmailNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );

        return (new MailMessage)
            ->subject('Verify your Vendify email')
            ->view('emails.base', [
                'preheader' => 'Verify your Vendify account email.',
                'heading' => 'Verify your email',
                'intro' => 'Confirm this email address so you can keep receiving account notices and security messages from Vendify.',
                'body' => 'This link expires in 60 minutes. If you did not create a Vendify account, you can ignore this message.',
                'actionText' => 'Verify email',
                'actionUrl' => $verificationUrl,
                'footerNote' => 'Vendify will never ask for your password or transaction PIN by email.',
            ]);
    }
}
