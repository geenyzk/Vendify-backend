<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provides mail configuration from the database with fallback to .env values.
 * Database settings are the primary source; .env values are used only as fallback.
 */
class MailSettingsService
{
    private static ?Setting $cachedSetting = null;

    /**
     * Get a mail configuration value from the database, with .env fallback.
     * Returns the database value if set (non-empty), otherwise falls back to .env.
     *
     * @param string $key The mail setting key (e.g., 'host', 'port', 'username')
     * @param string $envKey The environment variable name (e.g., 'MAIL_HOST')
     * @param mixed $envDefault The default value from .env config fallback
     * @return mixed The configuration value from database or .env
     */
    public static function get(string $key, string $envKey, mixed $envDefault = null): mixed
    {
        try {
            $setting = self::getSetting();
            $dbValue = $setting?->{"mail_{$key}"};

            // Use database value if it exists and is not empty
            if ($dbValue !== null && $dbValue !== '') {
                return $dbValue;
            }
        } catch (\Throwable $e) {
            // Log error but don't fail — fall through to .env fallback
            Log::warning("Failed to load mail settings from database: {$e->getMessage()}");
        }

        // Fall back to .env value
        return env($envKey, $envDefault);
    }

    /**
     * Get the mailer driver from database or .env.
     * Database value takes precedence.
     */
    public static function getMailer(): string
    {
        return self::get('mailer', 'MAIL_MAILER', 'log');
    }

    /**
     * Get SMTP host from database or .env.
     */
    public static function getHost(): ?string
    {
        return self::get('host', 'MAIL_HOST', 'email-smtp.eu-north-1.amazonaws.com') ?: null;
    }

    /**
     * Get SMTP port from database or .env.
     */
    public static function getPort(): int|string
    {
        return self::get('port', 'MAIL_PORT', 587) ?: 587;
    }

    /**
     * Get SMTP username from database or .env.
     */
    public static function getUsername(): ?string
    {
        return self::get('username', 'MAIL_USERNAME') ?: null;
    }

    /**
     * Get SMTP password from database or .env.
     */
    public static function getPassword(): ?string
    {
        return self::get('password', 'MAIL_PASSWORD') ?: null;
    }

    /**
     * Get encryption type (tls, ssl, none) from database or .env.
     */
    public static function getEncryption(): string
    {
        return self::get('encryption', 'MAIL_ENCRYPTION', 'tls') ?: 'tls';
    }

    /**
     * Laravel 12's SMTP transport expects a DSN scheme, not the older
     * encryption labels. Port 587 STARTTLS is "smtp"; port 465 implicit TLS
     * is "smtps".
     */
    public static function getScheme(): ?string
    {
        $scheme = self::get('scheme', 'MAIL_SCHEME');

        if ($scheme !== null && $scheme !== '') {
            return self::normalizeSmtpScheme((string) $scheme);
        }

        return self::normalizeSmtpScheme(self::getEncryption());
    }

    public static function normalizeSmtpScheme(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            '', 'none', 'null' => null,
            'ssl', 'smtps' => 'smtps',
            'tls', 'starttls', 'smtp' => 'smtp',
            default => $value,
        };
    }

    public static function getSenderDomain(): ?string
    {
        $address = self::getFromAddress();

        if (!$address || !str_contains($address, '@')) {
            return null;
        }

        return strtolower(trim(substr(strrchr($address, '@'), 1))) ?: null;
    }

    public static function getLocalDomain(): ?string
    {
        $configured = env('MAIL_EHLO_DOMAIN');

        if ($configured) {
            return strtolower(trim($configured));
        }

        $senderDomain = self::getSenderDomain();
        if ($senderDomain) {
            return $senderDomain;
        }

        $appHost = parse_url(env('APP_URL', ''), PHP_URL_HOST);

        if (!$appHost || in_array($appHost, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        return strtolower($appHost);
    }

    /**
     * Get from address from database or .env.
     */
    public static function getFromAddress(): ?string
    {
        return self::get('from_address', 'MAIL_FROM_ADDRESS') ?: null;
    }

    /**
     * Get from name from database or .env.
     */
    public static function getFromName(): ?string
    {
        return self::get('from_name', 'MAIL_FROM_NAME', 'Vendify') ?: null;
    }

    /**
     * Safely retrieve the settings record from the database.
     * Caches the result to avoid repeated queries within the same request.
     * Returns null if the database is not available or the settings table doesn't exist.
     */
    private static function getSetting(): ?Setting
    {
        if (self::$cachedSetting !== null) {
            return self::$cachedSetting;
        }

        try {
            // Check if the database connection is available
            if (!DB::connection()->getPdo()) {
                return null;
            }

            // Try to fetch the settings (typically id=1, the only row)
            self::$cachedSetting = Setting::first();
            return self::$cachedSetting;
        } catch (\Throwable $e) {
            // Database not yet available (during bootstrap) or table doesn't exist
            return null;
        }
    }

    /**
     * Clear the cached setting (useful for testing or after updates).
     */
    public static function clearCache(): void
    {
        self::$cachedSetting = null;
    }
}
