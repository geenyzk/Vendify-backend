<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A generic in-app (database-channel-only) notification — one class reused
 * for every real event (purchase success/fail, wallet transfer received,
 * airtime-to-cash reviewed, withdrawal reviewed, referral commission
 * earned, admin alerts) rather than one subclass per event type. `type` is
 * a free-form key the frontend uses to pick an icon; it isn't queried
 * against.
 *
 * Deliberately NOT queued (unlike BroadcastNotification): this is a single
 * lightweight database insert per call, and the whole point of an unread
 * badge is that it updates immediately, not whenever a queue worker next
 * runs.
 */
class AppNotification extends Notification
{
    public function __construct(
        protected string $type,
        protected string $title,
        protected string $body,
        protected array $data = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_merge($this->data, [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
        ]);
    }
}
