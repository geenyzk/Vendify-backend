<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the ORIGIN channel a transaction came through — the answer to "where
 * did this purchase originate?" rather than "which vendor executed it" (that is
 * the transaction's `provider` column).
 *
 * Precedence:
 *   1. An explicit override — set by the affiliate tunnel around a vend, since
 *      those requests arrive at the parent as ordinary API calls but originate
 *      from a child platform.
 *   2. The `X-Client-Platform` request header — the mobile app injects "app";
 *      an external integrator may send "api" (only trusted values are honoured).
 *   3. The acting user's role: a member of the `api` role is an integration
 *      consumer, so their traffic is API traffic.
 *   4. Default: the website.
 */
class TransactionPlatform
{
    public const API = 'api';
    public const WEB = 'web';
    public const APP = 'app';
    public const AFFILIATE = 'affiliate';
    public const BOT = 'bot';
    public const AGENT = 'agent';

    /** Every recognised origin, for validation and UI filters. */
    public const ALL = [
        self::API, self::WEB, self::APP, self::AFFILIATE, self::BOT, self::AGENT,
    ];

    private static ?string $override = null;

    /** Force the origin for the duration of $callback (affiliate tunnel vends). */
    public static function withOverride(string $platform, Closure $callback): mixed
    {
        $previous = self::$override;
        self::$override = $platform;
        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }

    /** The origin for the transaction being recorded right now. */
    public static function current(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $header = self::fromHeader();
        if ($header !== null) {
            return $header;
        }

        return self::fromActor(Auth::user()) ?? self::WEB;
    }

    private static function fromHeader(): ?string
    {
        try {
            $value = strtolower(trim((string) request()?->header('X-Client-Platform', '')));
        } catch (\Throwable) {
            return null;
        }

        // Never accept "affiliate" from a header — that origin is asserted only
        // by the tunnel itself, so a client can't forge an affiliate sale.
        return in_array($value, [self::WEB, self::APP, self::API], true) ? $value : null;
    }

    private static function fromActor(?User $user): ?string
    {
        if (!$user) {
            return null;
        }

        if ($user->user_type === self::API || ($user->role?->slug === self::API)) {
            return self::API;
        }

        return null;
    }
}
