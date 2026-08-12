<?php

namespace App\Support;

final class ProviderPlanPresentation
{
    /**
     * Split provider catalogue text without ever making the original
     * unavailable to clients. This is provider-neutral metadata: VTU.ng,
     * CheapDataHub, and future integrations use the same presentation path.
     *
     * @return array{original:?string, description:?string, confident:bool}
     */
    public static function from(?string $original, ?string $validity = null): array
    {
        $original = trim(preg_replace('/\s+/u', ' ', (string) $original) ?? '');
        $validity = trim(preg_replace('/\s+/u', ' ', (string) $validity) ?? '');

        if ($original === '') {
            return ['original' => null, 'description' => null, 'confident' => false];
        }

        $remaining = preg_replace('/\b\d+(?:\.\d+)?\s*(?:MB|GB|TB)\b/i', '', $original, 1, $sizeCount);
        if ($sizeCount !== 1 || ! is_string($remaining)) {
            return ['original' => $original, 'description' => $original, 'confident' => false];
        }

        if ($validity !== '') {
            $remaining = preg_replace('/'.preg_quote($validity, '/').'/i', '', $remaining, 1) ?? $remaining;
        }

        $remaining = preg_replace('/^[\s\-+•|,:;()]+|[\s\-+•|,:;()]+$/u', '', $remaining) ?? '';
        $remaining = trim(preg_replace('/\s+/u', ' ', $remaining) ?? '');

        // Provider shorthand such as "+ 5 mins" describes an included call
        // allowance, not another data amount.
        if (preg_match('/^(?:includes?\s+)?(\d+(?:\.\d+)?\s*(?:mins?|minutes?))(.*)$/i', $remaining, $minutes)) {
            $suffix = trim($minutes[2]);
            $remaining = 'Includes '.trim($minutes[1]).($suffix !== '' ? ' '.$suffix : '');
        } elseif ($remaining !== '') {
            $remaining = ucfirst($remaining);
        }

        return [
            'original' => $original,
            'description' => $remaining !== '' ? $remaining : null,
            'confident' => true,
        ];
    }
}
