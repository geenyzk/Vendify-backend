<?php

namespace App\Support;

use App\Models\Template;

/**
 * The catalogue of {{placeholders}} a notification template may use — the
 * single source of truth shared by the admin template editor and the AI
 * Manager's template tools.
 *
 * A template body can contain any `{{token}}`, but only tokens the send-time
 * code actually supplies get substituted; an unknown one is delivered to the
 * customer as the literal text "{{token}}". This catalogue is what "supported"
 * means: GLOBAL variables are available on every template, and each event adds
 * its own. Anything outside it is flagged as unknown so nobody ships a template
 * with a placeholder that will never fill in.
 *
 * Extend without touching code via config('templates.variables') — see
 * config/templates.php.
 */
class TemplateVariables
{
    /** Available on every template regardless of type/event. */
    private const GLOBAL = [
        'app_name' => 'Your platform name',
        'user_name' => "The customer's full name",
        'first_name' => "The customer's first name",
        'email' => "The customer's email address",
        'phone' => "The customer's phone number",
        'wallet_balance' => "The customer's current wallet balance (formatted)",
        'support_email' => 'Configured support email address',
        'support_phone' => 'Configured support phone number',
        'date' => "Today's date",
        'year' => 'The current year',
    ];

    /** Additional variables specific to each event template. */
    private const BY_EVENT = [
        'login' => [
            'login_time' => 'When the sign-in happened',
            'ip_address' => 'IP address the sign-in came from',
            'device' => 'Device / browser used to sign in',
        ],
        'register' => [
            'referral_code' => "The new customer's own referral code",
            'referred_by' => 'Name of whoever referred them, if any',
        ],
        'purchase' => [
            'service' => 'Service bought (airtime, data, cable, electricity, exam)',
            'product' => 'Specific product/plan purchased',
            'amount' => 'Amount charged (formatted)',
            'quantity' => 'Quantity or bundle size',
            'recipient' => 'Phone number / meter / smartcard credited',
            'reference' => 'Transaction reference',
            'status' => 'Transaction status',
        ],
        'wallet_credit' => [
            'amount' => 'Amount credited (formatted)',
            'reference' => 'Transaction reference',
            'source' => 'How the wallet was funded',
        ],
        'wallet_debit' => [
            'amount' => 'Amount debited (formatted)',
            'reference' => 'Transaction reference',
            'description' => 'What the debit was for',
        ],
    ];

    /** GLOBAL variables merged with any config additions. */
    public static function global(): array
    {
        return array_merge(self::GLOBAL, (array) config('templates.variables.global', []));
    }

    /**
     * Variables available to a template: the globals plus this event's own.
     * A null/broadcast event returns just the globals.
     *
     * @return array<string, string> name => description
     */
    public static function forEvent(?string $event): array
    {
        $eventVars = [];
        if ($event) {
            $eventVars = array_merge(
                self::BY_EVENT[$event] ?? [],
                (array) config("templates.variables.events.{$event}", []),
            );
        }

        return array_merge(self::global(), $eventVars);
    }

    /** Just the usable variable names for an event (globals + event). */
    public static function namesFor(?string $event): array
    {
        return array_keys(self::forEvent($event));
    }

    /**
     * Variables used in $content that this event does not supply, so they would
     * render literally. `custom_` is an escape hatch: prefix a placeholder with
     * it to intentionally declare a bespoke variable and skip the warning.
     *
     * @return array<int, string>
     */
    public static function unknownIn(?string $content, ?string $event): array
    {
        if (!$content) {
            return [];
        }

        preg_match_all('/{{\s*([a-zA-Z0-9_-]+)\s*}}/', $content, $matches);
        $used = array_unique($matches[1] ?? []);
        $known = self::namesFor($event);

        return array_values(array_filter(
            $used,
            fn (string $name) => !in_array($name, $known, true) && !str_starts_with($name, 'custom_'),
        ));
    }

    /**
     * Full catalogue for the UI / AI: globals plus every event's variables,
     * each as a {name, token, description} list.
     */
    public static function catalog(): array
    {
        $shape = static fn (array $vars): array => array_map(
            static fn (string $name, string $desc) => [
                'name' => $name,
                'token' => '{{' . $name . '}}',
                'description' => $desc,
            ],
            array_keys($vars),
            array_values($vars),
        );

        $events = [];
        foreach (Template::EVENTS as $event) {
            $eventOnly = array_diff_key(self::forEvent($event), self::global());
            $events[$event] = $shape($eventOnly);
        }

        return [
            'global' => $shape(self::global()),
            'events' => $events,
            'note' => 'Global variables work in any template. Event variables only work in that event\'s template. Prefix a placeholder with "custom_" to declare a bespoke variable on purpose.',
        ];
    }
}
