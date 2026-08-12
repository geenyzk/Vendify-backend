<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/** Separates upstream/admin diagnostics from safe customer-facing messages. */
class VendorErrorMessage
{
    public const TEMPORARY_FAILURE = 'We could not complete your purchase right now. Please try again later or contact support.';
    public const PLAN_UNAVAILABLE = 'This plan is temporarily unavailable. Please choose another plan or try again later.';
    public const PROCESSING = 'Your purchase is still processing. Please check your transaction history for updates.';

    public static function forCurrentUser(?string $message, string $status = 'fail', bool $allowStaffDetail = true): string
    {
        $message = trim((string) $message);

        // Staff need the provider's exact response to repair configuration,
        // funding and routing. Ordinary customers must not see those details.
        if ($allowStaffDetail && (bool) Auth::user()?->role?->is_staff) {
            return $message !== '' ? $message : self::fallback($status);
        }

        if ($status === 'pending') {
            return self::PROCESSING;
        }

        if (self::isPlanConfigurationFailure($message)) {
            return self::PLAN_UNAVAILABLE;
        }

        return self::TEMPORARY_FAILURE;
    }

    private static function fallback(string $status): string
    {
        return $status === 'pending' ? self::PROCESSING : self::TEMPORARY_FAILURE;
    }

    private static function isPlanConfigurationFailure(string $message): bool
    {
        return (bool) preg_match(
            '/plan\s*(id|mapping)|variation[_\s-]*id|no .* plan id|plan .* (configured|mapped)|invalid[_\s-]*variation|product[_\s-]*unavailable/i',
            $message,
        );
    }
}
