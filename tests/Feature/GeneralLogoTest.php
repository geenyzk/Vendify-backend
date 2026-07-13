<?php

use App\Http\Controllers\GeneralController;
use App\Models\General;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('keeps a stable public logo url across repeated uploads', function () {
    Storage::fake('public');
    config(['app.url' => 'https://example.test']);

    General::updateOrCreate(
        ['id' => 1],
        ['app_name' => 'Vendify', 'app_email' => 'support@example.com']
    );

    $controller = app(GeneralController::class);

    $firstFile = UploadedFile::fake()->image('logo-1.png', 120, 120);
    $firstRequest = Request::create('/general/logo', 'POST', [], [], ['logo' => $firstFile]);
    $controller->uploadLogo($firstRequest);

    $firstLogoUrl = General::findOrFail(1)->logo;

    expect($firstLogoUrl)->toBe('https://example.test/branding/logo');

    $secondFile = UploadedFile::fake()->image('logo-2.jpg', 120, 120);
    $secondRequest = Request::create('/general/logo', 'POST', [], [], ['logo' => $secondFile]);
    $controller->uploadLogo($secondRequest);

    $general = General::findOrFail(1);

    expect($general->logo)->toBe($firstLogoUrl)
        ->and(Storage::disk('public')->exists('logos/brand-logo'))->toBeTrue();
});
