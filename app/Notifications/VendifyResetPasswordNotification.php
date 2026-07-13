<?php

namespace App\Notifications;

use App\Support\MailDeliverability;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendifyResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(explode(',', env('FRONTEND_URL', 'http://localhost:5173'))[0], '/');
        $resetUrl = "{$frontendUrl}/reset-password?token={$this->token}&email=" . urlencode($notifiable->getEmailForPasswordReset());

        $viewData = [
            'preheader' => 'Use this secure link to reset your Vendify password.',
            'heading' => 'Reset your password',
            'intro' => 'We received a request to reset the password for your Vendify account.',
            'body' => 'Use the button below to choose a new password. If you did not request this, ignore this email and your password will remain unchanged.',
            'actionText' => 'Reset password',
            'actionUrl' => $resetUrl,
            'footerNote' => 'For your safety, do not share this email or your password with anyone.',
        ];

        return (new MailMessage)
            ->subject('Reset your Vendify password')
            ->view(['html' => 'emails.base', 'text' => 'emails.plain'], $viewData)
            ->withSymfonyMessage(fn ($message) => MailDeliverability::apply($message, 'reset-password'));
    }
}
