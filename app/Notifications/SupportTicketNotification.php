<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SupportTicketNotification extends Notification
{
    use Queueable;

    public function __construct(private SupportTicket $ticket, private string $event) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toArray(object $notifiable): array
    {
        $titles = [
            'created' => 'Support ticket created',
            'admin_replied' => 'Support replied to your ticket',
            'customer_replied' => 'Customer replied to a support ticket',
            'status_changed' => 'Support ticket status updated',
            'resolved' => 'Support ticket resolved',
        ];

        return [
            'type' => 'support_ticket',
            'event' => $this->event,
            'title' => $titles[$this->event] ?? 'Support ticket updated',
            'body' => $this->ticket->subject,
            'ticket_id' => $this->ticket->id,
            'ticket_reference' => $this->ticket->reference,
            'status' => $this->ticket->status,
        ];
    }
}
