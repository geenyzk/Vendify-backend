<?php

namespace App\Support;

use InvalidArgumentException;

class WhatsAppPhoneNumber
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') throw new InvalidArgumentException('A WhatsApp phone number is required.');

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
        if (str_starts_with($digits, '0')) $digits = '234' . substr($digits, 1);

        if (!preg_match('/^[1-9][0-9]{7,14}$/', $digits)) {
            throw new InvalidArgumentException('Enter a valid international WhatsApp phone number.');
        }

        return '+' . $digits;
    }

    public static function target(string $canonical): string
    {
        return ltrim($canonical, '+');
    }
}
