<?php

use App\Http\Controllers\AdminController;
use App\Models\Provider;
use App\Models\Vendor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('providers');

    Schema::create('providers', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('category')->nullable();
        $table->string('sub_category')->nullable();
        $table->string('webhook_access')->default('1');
        $table->decimal('charge_fee', 12, 2)->nullable();
        $table->decimal('charge_fee_cap', 12, 2)->nullable();
        $table->string('charge_type')->nullable();
        $table->decimal('withdrawal_fee', 12, 2)->nullable();
        $table->timestamps();
    });
});

it('preserves provider webhook access when an update submits null', function () {
    $provider = Provider::create([
        'name' => 'Payment provider',
        'category' => 'vendor',
        'webhook_access' => '0',
    ]);

    $request = Request::create('/api/table/providers/' . $provider->id, 'PUT', [
        'charge_fee' => 1,
        'charge_fee_cap' => 50,
        'charge_type' => 'percent',
        'withdrawal_fee' => 0.01,
        'webhook_access' => null,
    ]);

    $response = AdminController::universalCreateOrUpdate($request, 'providers', $provider->id);

    expect($response->getStatusCode())->toBe(200)
        ->and($provider->fresh()->webhook_access)->toBe('0');
});

it('defaults null webhook access for newly registered providers and vendors', function (string $modelClass) {
    $record = $modelClass::create([
        'name' => 'New service',
        'category' => 'vendor',
        'sub_category' => 'misc',
        'webhook_access' => null,
    ]);

    expect($record->fresh()->webhook_access)->toBe('1');
})->with([
    'provider model' => Provider::class,
    'vendor model' => Vendor::class,
]);
