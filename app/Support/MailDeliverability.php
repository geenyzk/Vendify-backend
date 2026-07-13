<?php

namespace App\Support;

use App\Services\MailSettingsService;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

class MailDeliverability
{
    public static function supportAddress(): string
    {
        return env('SUPPORT_EMAIL')
            ?: 'support@' . (MailSettingsService::getSenderDomain() ?: 'vendify.com.ng');
    }

    public static function messageIdDomain(): string
    {
        return MailSettingsService::getSenderDomain()
            ?: MailSettingsService::getLocalDomain()
            ?: 'vendify.com.ng';
    }

    public static function headers(string $category): array
    {
        $headers = [
            'X-Auto-Response-Suppress' => 'All',
            'X-Entity-Ref-ID' => (string) Str::uuid(),
            'Feedback-ID' => "vendify:{$category}:transactional",
        ];

        if (in_array($category, ['broadcast', 'admin-notification'], true)) {
            $support = self::supportAddress();
            $headers['List-Unsubscribe'] = "<mailto:{$support}?subject=Unsubscribe>";
        }

        return $headers;
    }

    public static function apply(Email $message, string $category): void
    {
        $headers = $message->getHeaders();
        $headers->addIdHeader('Message-ID', (string) Str::uuid() . '@' . self::messageIdDomain());

        foreach (self::headers($category) as $name => $value) {
            if (!$headers->has($name)) {
                $headers->addTextHeader($name, $value);
            }
        }
    }
}
