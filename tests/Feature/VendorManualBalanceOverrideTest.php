<?php

use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('providers');

    Schema::create('providers', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->string('name')->nullable();
        $table->string('sub_category')->nullable();
        $table->string('category')->nullable();
        $table->decimal('manual_balance', 15, 2)->nullable();
        $table->boolean('active')->default(true);
    });
});

test('vendors can use a manual balance override when no live balance api is available', function () {
    $vendor = Vendor::create([
        'name' => 'Ogdams',
        'sub_category' => 'ogdams',
        'manual_balance' => 2500.50,
    ]);

    expect($vendor->balance)->toBe(2500.5)
        ->and($vendor->manual_balance)->toBe(2500.5);

    $vendor->forceFill(['manual_balance' => 3000.00])->save();

    expect($vendor->fresh()->balance)->toBe(3000.0);
});
