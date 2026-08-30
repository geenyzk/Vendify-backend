<?php

namespace App\Services;

use App\Models\DataCategory;

class DataPlanCategoryClassifier
{
    /** @return array{slug:string,days:?int} */
    public function classify(string $name = '', string $validity = '', string $providerType = '', string $description = ''): array
    {
        $text = strtolower(implode(' ', array_filter([$name, $validity, $providerType, $description])));
        $keywords = [
            'social' => ['social', 'whatsapp', 'instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'snapchat'],
            'night' => ['night', 'midnight'],
            'weekend' => ['weekend'],
            'unlimited' => ['unlimited'],
        ];
        foreach ($keywords as $slug => $words) {
            foreach ($words as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $text)) {
                    return ['slug' => $slug, 'days' => $this->normalizeValidityDays($validity ?: $text)];
                }
            }
        }

        $days = $this->normalizeValidityDays($validity ?: $text);
        $slug = match (true) {
            $days !== null && $days <= 2 => 'daily',
            $days !== null && $days <= 8 => 'weekly',
            $days !== null && $days >= 21 && $days <= 35 => 'monthly',
            $days !== null && $days >= 36 && $days <= 70 => 'two-months',
            $days !== null && $days >= 71 && $days <= 100 => 'three-months',
            $days !== null && $days >= 101 && $days <= 364 => 'long-term',
            $days !== null && $days >= 365 => 'yearly',
            default => 'special',
        };

        return compact('slug', 'days');
    }

    public function normalizeValidityDays(?string $validity): ?int
    {
        $value = strtolower(trim((string) $validity));
        if ($value === '') return null;
        if (! preg_match('/(\d+(?:\.\d+)?)\s*(hour|hours|day|days|week|weeks|month|months|year|years)\b/i', $value, $match)) {
            return null;
        }
        $amount = (float) $match[1];
        $days = match (strtolower($match[2])) {
            'hour', 'hours' => $amount / 24,
            'day', 'days' => $amount,
            'week', 'weeks' => $amount * 7,
            'month', 'months' => $amount * 30,
            'year', 'years' => $amount * 365,
        };

        return max(1, (int) round($days));
    }

    public function categoryId(string $name = '', string $validity = '', string $providerType = '', string $description = ''): ?int
    {
        return DataCategory::where('slug', $this->classify($name, $validity, $providerType, $description)['slug'])->value('id');
    }
}
