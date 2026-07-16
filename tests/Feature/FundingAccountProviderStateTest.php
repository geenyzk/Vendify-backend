<?php

use App\Models\Bank;
use App\Models\Provider;
use App\Models\ServiceControl;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('username')->unique();
        $table->string('fullname');
        $table->string('email')->unique();
        $table->string('phone')->unique();
        $table->string('password');
        $table->string('status')->default('active');
        $table->string('referral_code')->nullable();
        $table->rememberToken();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('providers', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('category')->nullable();
        $table->string('sub_category')->nullable();
        $table->boolean('active')->default(false);
        $table->string('webhook_access')->default('1');
        $table->timestamps();
    });

    Schema::create('service_controls', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('category');
        $table->string('sub_category');
        $table->boolean('isActive');
        $table->boolean('isDevLock');
        $table->timestamps();
    });

    Schema::create('banks', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('account_type')->nullable();
        $table->string('bank_account')->nullable();
        $table->string('bank_name')->nullable();
        $table->string('account_name')->nullable();
        $table->string('provider')->nullable();
        $table->string('status')->nullable();
        $table->string('currency')->nullable();
        $table->decimal('amount', 8, 2)->default(0);
        $table->timestamp('expired_at')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('banks');
    Schema::dropIfExists('service_controls');
    Schema::dropIfExists('providers');
    Schema::dropIfExists('users');
});

function fundingAccountUser(): User
{
    return User::query()->create([
        'username' => 'funding-user',
        'fullname' => 'Funding User',
        'email' => 'funding@example.com',
        'phone' => '08012345678',
        'password' => 'password',
        'status' => 'active',
    ]);
}

function fundingProvider(string $name, bool $providerActive, bool $controlActive): void
{
    Provider::query()->create([
        'name' => $name,
        'category' => 'payment',
        'sub_category' => 'payment',
        'active' => $providerActive,
    ]);

    ServiceControl::query()->create([
        'name' => $name,
        'category' => 'transaction',
        'sub_category' => 'payment gateway',
        'isActive' => $controlActive,
        'isDevLock' => false,
    ]);
}

it('does not display a saved account belonging to a disabled provider', function () {
    $user = fundingAccountUser();
    fundingProvider('flutterwave', false, false);
    fundingProvider('payment point', true, true);

    Bank::query()->create([
        'user_id' => $user->id,
        'account_type' => 'virtual',
        'bank_account' => '9907389272',
        'bank_name' => 'Indulge MFB',
        'provider' => 'flutterwave',
        'status' => 'active',
    ]);
    Bank::query()->create([
        'user_id' => $user->id,
        'account_type' => 'virtual',
        'bank_account' => '6679854996',
        'bank_name' => 'PalmPay',
        'provider' => 'payment point',
        'status' => 'active',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('account.account_number', '6679854996')
        ->assertJsonPath('account.bank_name', 'PalmPay');
});

it('does not load inactive providers for account generation', function () {
    fundingProvider('flutterwave', false, true);
    fundingProvider('payment point', true, true);

    expect(Provider::query()->getPaymentProviders()->pluck('name')->all())
        ->toBe(['payment point']);
});
