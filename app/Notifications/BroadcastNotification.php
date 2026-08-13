<?php

namespace App\Notifications;

use App\Support\MailDeliverability;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class BroadcastNotification extends Notification
{
    use Queueable;

    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function via(object $notifiable): array
    {
        $channels = [];

        if (in_array('Email', $this->data['channels'])) {
            $channels[] = 'mail';
        }

        if (in_array('sms', $this->data['channels'])) {
            $channels[] = 'nexmo'; // or 'vonage'
        }

        if (in_array('database', $this->data['channels'])) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->data['emailSubject'] ?: 'Vendify update';
        $body = $this->data['emailBody'] ?: 'No content';
        $viewData = [
            'preheader' => $subject,
            'heading' => $subject,
            'intro' => 'A Vendify update for your account.',
            'body' => $body,
            'footerNote' => 'You are receiving this because you have a Vendify account.',
        ];

        return (new MailMessage)
            ->subject($subject)
            ->view(['html' => 'emails.base', 'text' => 'emails.plain'], $viewData)
            ->withSymfonyMessage(fn ($message) => MailDeliverability::apply(
                $message,
                $this->data['emailCategory'] ?? 'transactional',
            ));
    }

    public function toArray(object $notifiable): array
    {
        Log::info("database...");
        return [
            'type'     => 'broadcast',
            'title'    => $this->data['notifTitle'] ?: 'Notification',
            'body'     => $this->data['notifMessage'] ?: 'No content',
            // Retain the legacy key for any older consumers.
            'message'  => $this->data['notifMessage'] ?: 'No content',
            'priority' => $this->data['priorityHigh'],
            'channels' => $this->data['channels'],
        ];
    }
}
