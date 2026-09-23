<?php

namespace App\Support;

/**
 * Nigerian mobile prefix → network, in one place.
 *
 * Used to validate that a recharge number matches the chosen network, and to
 * recover the network of a data/airtime order recorded before transactions
 * carried a `network` column of their own. Number portability means a ported
 * line resolves to the network that issued the prefix rather than the one
 * serving it today, so this is only ever a fallback — a stored network always
 * wins over a guess from the digits.
 */
final class PhoneNetwork
{
    /** @var array<string, array<int, string>> */
    private const PREFIXES = [
        'mtn' => ['0803', '0806', '0810', '0813', '0814', '0816', '0703', '0706', '0903', '0906', '0913', '0916'],
        'airtel' => ['0802', '0808', '0812', '0708', '0701', '0902', '0907', '0901', '0912'],
        'glo' => ['0805', '0807', '0811', '0815', '0705', '0905', '0915'],
        '9mobile' => ['0809', '0817', '0818', '0909', '0908'],
    ];

    /**
     * Does this number's prefix belong to the given network?
     *
     * Deliberately reads the first four digits as typed, without the
     * international-form normalisation forPhone() applies: this backs a
     * validation rule that has always required the local 0… form, and
     * accepting more here would change what a purchase form lets through.
     */
    public static function matches(?string $phone, ?string $network): bool
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        $prefix = strlen($digits) >= 4 ? substr($digits, 0, 4) : null;

        return $prefix !== null
            && in_array($prefix, self::PREFIXES[strtolower((string) $network)] ?? [], true);
    }

    /** The network that issued this number's prefix, or null. */
    public static function forPhone(?string $phone): ?string
    {
        $prefix = self::prefix($phone);
        if ($prefix === null) {
            return null;
        }

        foreach (self::PREFIXES as $network => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return $network;
            }
        }

        return null;
    }

    /**
     * The leading four digits in local (0…) form. Stored recipients arrive
     * as 0803…, 234803… and +234 803…, so normalise to one shape first.
     */
    private static function prefix(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '234')) {
            $digits = '0'.substr($digits, 3);
        } elseif (! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        return strlen($digits) >= 4 ? substr($digits, 0, 4) : null;
    }
}
