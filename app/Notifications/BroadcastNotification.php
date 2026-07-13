<?php

namespace App\Notifications;

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
        return (new MailMessage)
            ->subject($this->data['emailSubject'] ?: 'No Subject')
            ->line($this->data['emailBody'] ?: 'No Content');
    }

    public function toArray(object $notifiable): array
    {
        Log::info("database...");
        return [
            'title'    => $this->data['notifTitle'] ?: 'Notification',
            'message'  => $this->data['notifMessage'] ?: 'No content',
            'priority' => $this->data['priorityHigh'],
            'channels' => $this->data['channels'],
        ];
    }
}
