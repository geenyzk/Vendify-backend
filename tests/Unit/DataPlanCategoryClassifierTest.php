<?php

use App\Services\DataPlanCategoryClassifier;

test('normalizes common validity formats', function (string $value, int $days) {
    expect(app(DataPlanCategoryClassifier::class)->normalizeValidityDays($value))->toBe($days);
})->with([
    ['1 Day', 1], ['7 Days', 7], ['1 Month', 30], ['2 Months', 60],
    ['3 Months', 90], ['365 Days', 365], ['1 Year', 365],
]);

test('classifies merchandising categories deterministically', function (string $name, string $validity, string $slug) {
    expect(app(DataPlanCategoryClassifier::class)->classify($name, $validity)['slug'])->toBe($slug);
})->with([
    ['Basic', '1 Day', 'daily'], ['Basic', '7 Days', 'weekly'], ['Basic', '30 Days', 'monthly'],
    ['Basic', '2 Months', 'two-months'], ['Basic', '90 Days', 'three-months'],
    ['Basic', '180 Days', 'long-term'], ['Basic', '1 Year', 'yearly'],
    ['WhatsApp bundle', '30 Days', 'social'], ['Midnight plan', '1 Day', 'night'],
    ['Weekend bundle', '3 Days', 'weekend'], ['Unlimited data', '30 Days', 'unlimited'],
    ['Odd bundle', '14 Days', 'special'],
]);
