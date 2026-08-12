<?php

use App\Support\ProviderPlanPresentation;

it('derives customer friendly provider plan qualifiers', function (string $original, string $validity, ?string $description) {
    expect(ProviderPlanPresentation::from($original, $validity))->toBe([
        'original' => $original,
        'description' => $description,
        'confident' => true,
    ]);
})->with([
    ['1.75GB - Sunday', '', 'Sunday'],
    ['1GB + 5 mins - 7 Days', '7 Days', 'Includes 5 mins'],
    ['2.2GB - Weekend', '', 'Weekend'],
    ['500MB Gift - 30 Days', '30 Days', 'Gift'],
    ['3GB Day / 1GB Night - 7 Days', '7 Days', 'Day / 1GB Night'],
]);

it('preserves the untouched provider name when parsing is uncertain', function () {
    expect(ProviderPlanPresentation::from('Night bundle with unusual allocation', ''))->toBe([
        'original' => 'Night bundle with unusual allocation',
        'description' => 'Night bundle with unusual allocation',
        'confident' => false,
    ]);
});
