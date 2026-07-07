<?php

namespace App\Class\ChildSync;

/**
 * HMAC sign/verify for the parent<->child channel. No child-specific
 * knowledge lives here — the same class is used to verify inbound pushes
 * and (later) to sign outbound directive responses.
 */
class PayloadSigner
{
    public static function sign(string $secret, string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    public static function verify(string $secret, string $timestamp, string $rawBody, string $signature): bool
    {
        return hash_equals(self::sign($secret, $timestamp, $rawBody), $signature);
    }
}
