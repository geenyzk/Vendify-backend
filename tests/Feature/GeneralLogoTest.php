<?php

use App\Http\Controllers\GeneralController;
use App\Models\General;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

it('keeps a stable public logo url across repeated uploads', function () {
    Schema::create('generals', function ($table) {
        $table->id();
        $table->timestamps();
        $table->string('app_name')->default('Laravel');
        $table->string('app_phone')->default('#');
        $table->string('app_address')->default('#');
        $table->string('app_email')->default('#');
        $table->string('bvn')->default('#');
        $table->string('bankName')->default('#');
        $table->string('accountName')->default('#');
        $table->string('accountNumber')->default('#');
        $table->string('logo')->default('#');
        $table->string('meta_title')->nullable();
        $table->string('meta_description')->nullable();
    });

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

    expect($firstLogoUrl)->toBe('/branding/logo');

    $secondFile = UploadedFile::fake()->image('logo-2.jpg', 120, 120);
    $secondRequest = Request::create('/general/logo', 'POST', [], [], ['logo' => $secondFile]);
    $controller->uploadLogo($secondRequest);

    $general = General::findOrFail(1);

    expect($general->logo)->toBe($firstLogoUrl)
        ->and(Storage::disk('public')->exists('logos/brand-logo'))->toBeTrue();

    Schema::dropIfExists('generals');
});
