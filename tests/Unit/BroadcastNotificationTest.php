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

test('broadcast customer emails use deliverable branded mail views', function () {
    $notification = new BroadcastNotification([
        'channels' => ['Email'],
        'emailSubject' => 'Promo',
        'emailBody' => 'Hello customer',
        'notifTitle' => null,
        'notifMessage' => null,
        'priorityHigh' => false,
    ]);

    $message = $notification->toMail(new stdClass());

    expect($message->subject)->toBe('Promo')
        ->and($message->view)->toBe(['html' => 'emails.base', 'text' => 'emails.plain'])
        ->and($message->viewData['body'])->toBe('Hello customer')
        ->and($message->callbacks)->toHaveCount(1);
});
