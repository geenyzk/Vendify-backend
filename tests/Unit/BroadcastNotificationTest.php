<?php

use App\Notifications\BroadcastNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

test('broadcast customer emails are sent synchronously', function () {
    $notification = new BroadcastNotification([
        'channels' => ['Email'],
        'emailSubject' => 'Promo',
        'emailBody' => 'Hello customer',
        'notifTitle' => null,
        'notifMessage' => null,
        'priorityHigh' => false,
    ]);

    expect($notification)->not->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via(new stdClass()))->toBe(['mail']);
});
