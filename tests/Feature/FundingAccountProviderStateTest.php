<?php

use App\Models\Bank;
use App\Http\Controllers\ServiceControlController;
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
    fundingProvider('PaymentPoint', true, true);

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

it('does not display an incomplete account row as ready', function () {
    $user = fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);

    Bank::query()->create([
        'user_id' => $user->id,
        'account_type' => 'virtual',
        'bank_account' => '6679854996',
        'bank_name' => 'PalmPay',
        'provider' => 'payment point',
        'status' => 'active',
    ]);
    Bank::query()->create([
        'user_id' => $user->id,
        'account_type' => 'virtual',
        'bank_account' => '   ',
        'bank_name' => '',
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

it('reports automatic provisioning without asking the customer to complete setup', function () {
    $user = fundingAccountUser();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/wallet/funding-account')
        ->assertAccepted()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('retryable', true)
        ->assertJsonPath('message', 'Your bank details are being created automatically. No extra setup is required.');
});

it('does not load inactive providers for account generation', function () {
    fundingProvider('flutterwave', false, true);
    fundingProvider('PaymentPoint', true, true);

    expect(Provider::query()->getPaymentProviders()->pluck('name')->all())
        ->toBe(['PaymentPoint']);
});

it('matches enabled PaymentPoint controls regardless of spaces or case', function () {
    fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);

    ServiceControl::query()->where('name', 'PaymentPoint')->update([
        'name' => 'payment point',
    ]);

    expect(Provider::query()->getPaymentProviders()->pluck('name')->all())
        ->toBe(['PaymentPoint']);
});

it('never uses a dev locked PaymentPoint control', function () {
    fundingAccountUser();
    fundingProvider('PaymentPoint', true, true);

    ServiceControl::query()->where('name', 'PaymentPoint')->update([
        'isDevLock' => true,
    ]);

    expect(Provider::query()->getPaymentProviders()->get())->toBeEmpty();
});

it('repairs a locked PaymentPoint control while preserving an enabled duplicate', function () {
    ServiceControl::query()->create([
        'name' => 'payment point',
        'category' => 'transaction',
        'sub_category' => 'payment gateway',
        'isActive' => false,
        'isDevLock' => true,
    ]);
    ServiceControl::query()->create([
        'name' => 'PaymentPoint',
        'category' => 'transaction',
        'sub_category' => 'payment gateway',
        'isActive' => true,
        'isDevLock' => false,
    ]);

    $migration = require database_path('migrations/2026_10_08_230000_repair_payment_point_service_control.php');
    $migration->up();

    $controls = ServiceControl::query()
        ->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", ['paymentpoint'])
        ->get();

    expect($controls)->toHaveCount(1)
        ->and($controls->first()->name)->toBe('payment point')
        ->and($controls->first()->isActive)->toBeTrue()
        ->and($controls->first()->isDevLock)->toBeFalse();
});

it('unlocks the existing PaymentPoint control without changing other dev locks', function () {
    fundingProvider('PaymentPoint', true, true);
    fundingProvider('paystack', true, false);

    ServiceControl::query()->update(['isDevLock' => true]);
    ServiceControl::query()->where('name', 'PaymentPoint')->update(['name' => 'payment point']);

    (new ServiceControlController)->index();

    expect(ServiceControl::query()->whereRaw("LOWER(REPLACE(TRIM(name), ' ', '')) = ?", ['paymentpoint'])->count())
        ->toBe(1)
        ->and(ServiceControl::query()->where('name', 'payment point')->value('isDevLock'))->toBeFalse()
        ->and(ServiceControl::query()->where('name', 'paystack')->value('isDevLock'))->toBeTrue();
});
