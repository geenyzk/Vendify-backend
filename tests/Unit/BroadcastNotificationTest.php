<?php

use App\Notifications\BroadcastNotification;
use App\Support\MailDeliverability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Mime\Email;

function renderedBroadcastEmail(string $category): Email
{
    $notification = new BroadcastNotification([
        'channels' => ['Email'],
        'emailSubject' => 'Account update',
        'emailBody' => 'Hello customer',
        'emailCategory' => $category,
        'notifTitle' => null,
        'notifMessage' => null,
        'priorityHigh' => false,
    ]);

    $mailMessage = $notification->toMail(new stdClass());
    $email = new Email();
    foreach ($mailMessage->callbacks as $callback) {
        $callback($email);
    }

    return $email;
}

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

test('transactional broadcast email has no list unsubscribe headers', function () {
    $headers = renderedBroadcastEmail('transactional')->getHeaders();

    expect($headers->has('List-Unsubscribe'))->toBeFalse()
        ->and($headers->has('List-Unsubscribe-Post'))->toBeFalse();
});

test('marketing broadcast email has a list unsubscribe header', function () {
    $headers = renderedBroadcastEmail('marketing')->getHeaders();

    expect($headers->has('List-Unsubscribe'))->toBeTrue()
        ->and($headers->get('List-Unsubscribe')->getBodyAsString())->toContain('mailto:')
        // One-click requires an HTTPS unsubscribe endpoint, which this app
        // does not currently provide. Do not advertise unsupported behavior.
        ->and($headers->has('List-Unsubscribe-Post'))->toBeFalse();
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

test('email bodies apply a branded inline style to links', function () {
    $body = "Visit https://vendify.com.ng/login and <a href='https://example.com'>Example</a>";

    $styled = MailDeliverability::styleLinks($body);

    expect($styled)
        ->toContain('<a href="https://vendify.com.ng/login"')
        ->toContain('color:#ff7a1a !important')
        ->toContain('font-weight:700')
        ->toContain('text-decoration:underline')
        ->toContain('href="https://example.com"')
        ->toContain('style="color:#ff7a1a !important;font-weight:700;text-decoration:underline;"');
});
