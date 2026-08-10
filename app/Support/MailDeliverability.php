<?php

namespace App\Support;

use App\Services\MailSettingsService;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

class MailDeliverability
{
    public const LINK_STYLE = 'color:#ff7a1a !important;font-weight:700;text-decoration:underline;';

    public static function supportAddress(): string
    {
        return env('SUPPORT_EMAIL')
            ?: 'support@' . (MailSettingsService::getSenderDomain() ?: 'vendify.com.ng');
    }

    public static function styleLinks(string $content): string
    {
        if ($content === '') {
            return '';
        }

        $parts = preg_split('/(<a\b[^>]*>|<\/a>|<[^>]+>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $content;
        }

        foreach ($parts as $index => $part) {
            if ($part === '' || preg_match('/^<.*>$/', $part) === 1) {
                if (str_starts_with(strtolower($part), '<a')) {
                    $parts[$index] = self::applyLinkStyleToTag($part);
                }

                continue;
            }

            $parts[$index] = preg_replace_callback(
                '/(?<!["\'])((https?:\/\/[^\s<>"\']+))/i',
                fn (array $matches) => '<a href="' . htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '" style="' . self::LINK_STYLE . '">' . $matches[1] . '</a>',
                $part,
            ) ?? $part;
        }

        return implode('', $parts);
    }

    protected static function applyLinkStyleToTag(string $tag): string
    {
        if (preg_match('/\sstyle\s*=\s*["\'].*?["\']/i', $tag) === 1) {
            return preg_replace_callback(
                '/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i',
                function (array $matches): string {
                    $style = trim($matches[1], '"\'');
                    $normalized = trim(rtrim($style, '; ') . ';' . self::LINK_STYLE, '; ');

                    if (stripos($normalized, 'color:#ff7a1a') === false || stripos($normalized, 'font-weight:700') === false || stripos($normalized, 'text-decoration:underline') === false) {
                        return ' style="' . $normalized . '"';
                    }

                    return ' style="' . $normalized . '"';
                },
                $tag,
            ) ?? $tag;
        }

        return preg_replace('/<a\b([^>]*)>/i', '<a$1 style="' . self::LINK_STYLE . '">', $tag) ?? $tag;
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
