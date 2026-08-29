<?php

use App\Classes\Vendor\Providers\VTUNg;
use App\Classes\VTUServices\VTUServiceFactory;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Provider;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\TransactionController;
use App\Http\Resources\VendorResource;
use App\Jobs\ReconcileVTUNgTransaction;
use App\Notifications\AppNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    foreach (['event_awards', 'events', 'cashback_rates', 'settings', 'transactions', 'users', 'providers'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('users', function (Blueprint $t) {
        $t->id(); $t->string('username'); $t->string('fullname'); $t->string('email');
        $t->string('phone'); $t->string('password'); $t->string('user_type')->default('user');
        $t->decimal('wallet_balance', 12, 2)->default(0); $t->decimal('referral_balance', 12, 2)->default(0);
        $t->decimal('total_referral_earnings', 12, 2)->default(0); $t->boolean('is_active')->default(true);
        $t->boolean('is_verified')->default(false); $t->string('status')->default('active');
        $t->string('referral_code')->nullable(); $t->unsignedBigInteger('referred_by')->nullable();
        $t->rememberToken(); $t->timestamps(); $t->softDeletes();
    });
    Schema::create('transactions', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('user_id'); $t->string('transaction_type');
        $t->string('provider')->nullable(); $t->decimal('amount', 15, 2); $t->string('status');
        $t->string('transaction_reference')->unique(); $t->string('payment_reference')->nullable();
        $t->decimal('balance_before', 15, 2)->default(0); $t->decimal('balance_after', 15, 2)->default(0);
        $t->timestamp('completed_at')->nullable(); $t->timestamp('refunded_at')->nullable();
        $t->string('refund_reason')->nullable(); $t->string('response_message')->nullable();
        $t->decimal('discount_amount', 15, 2)->default(0); $t->decimal('service_fee', 15, 2)->default(0);
        $t->double('quantity', 8, 2)->default(1); $t->string('account_or_phone')->nullable();
        $t->string('funding_method')->nullable(); $t->string('platform')->nullable();
        $t->string('receiver')->nullable(); $t->string('plan_type')->nullable(); $t->string('token')->nullable();
        $t->unsignedBigInteger('promotion_id')->nullable(); $t->decimal('cost', 15, 2)->nullable();
        $t->string('related_reference')->nullable(); $t->timestamps();
    });
    Schema::create('providers', function (Blueprint $t) {
        $t->id(); $t->string('name')->nullable(); $t->string('category')->nullable();
        $t->string('sub_category')->nullable(); $t->string('base_url')->nullable();
        $t->string('api_key')->nullable(); $t->string('username')->nullable();
        $t->string('password')->nullable(); $t->boolean('active')->default(true); $t->timestamps();
    });
    Schema::create('settings', function (Blueprint $t) {
        $t->id(); $t->decimal('referral_commission_rate')->default(0);
    });
    Schema::create('cashback_rates', function (Blueprint $t) {
        $t->id(); $t->string('service_type'); $t->decimal('percentage')->default(0); $t->boolean('active')->default(false); $t->timestamps();
    });
    Schema::create('events', function (Blueprint $t) {
        $t->id(); $t->string('name')->nullable(); $t->string('metric')->nullable(); $t->decimal('threshold')->default(0);
        $t->string('service_type')->nullable(); $t->string('reward_type')->default('badge'); $t->decimal('cash_amount')->default(0);
        $t->boolean('repeatable')->default(false); $t->boolean('active')->default(false); $t->timestamps();
    });
    Schema::create('event_awards', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('event_id'); $t->unsignedBigInteger('user_id');
        $t->unsignedInteger('times_earned')->default(0); $t->timestamp('last_earned_at')->nullable(); $t->timestamps();
    });
    Notification::fake();
    Queue::fake();
});

it('reuses one cached VTU.ng jwt for multiple airtime requests', function () {
    $vendor = Vendor::create([
        'name' => 'VTU.ng', 'category' => 'vendor', 'sub_category' => 'vtu_ng',
        'base_url' => 'https://vtu.test/api/v2', 'username' => 'user', 'password' => 'pass', 'active' => true,
    ]);
    Http::fake([
        'https://vtu.test/jwt-auth/v1/token' => Http::response(['token' => 'jwt-one']),
        'https://vtu.test/api/v2/airtime' => Http::response(['code' => 'success', 'data' => ['status' => 'completed-api']]),
    ]);
    $client = new VTUNg($vendor);
    $payload = $client->formatPayload('airtime', ['tx_ref' => 'JWT-1', 'phone' => '08012345678', 'network' => 'mtn', 'amount' => 100]);
    $client->sendRequest('airtime', $payload);
    $client->sendRequest('airtime', $payload);

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->url() === 'https://vtu.test/api/v2/airtime'
        && $request->hasHeader('Authorization', 'Bearer jwt-one'));
});

it('refreshes an invalid VTU.ng token once and retries airtime once', function () {
    $vendor = Vendor::create([
        'name' => 'VTU.ng', 'category' => 'vendor', 'sub_category' => 'vtu_ng',
        'base_url' => 'https://vtu.test/api/v2', 'username' => 'user', 'password' => 'pass', 'active' => true,
    ]);
    Cache::put("vtu-ng:token:{$vendor->id}", 'expired-token', now()->addHour());
    Http::fake([
        'https://vtu.test/api/v2/airtime' => Http::sequence()
            ->push(['code' => 'jwt_auth_invalid_token'], 401)
            ->push(['code' => 'success', 'data' => ['status' => 'completed-api']], 200),
        'https://vtu.test/jwt-auth/v1/token' => Http::response(['token' => 'fresh-token']),
    ]);

    (new VTUNg($vendor))->sendRequest('airtime', [
        'request_id' => 'vendify_REFRESH', 'phone' => '08012345678', 'service_id' => 'mtn', 'amount' => 100,
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://vtu.test/api/v2/airtime'
        && $request->hasHeader('Authorization', 'Bearer fresh-token'));
});

function vtuNgFixture(string $status): array
{
    $user = User::create([
        'username' => uniqid('vtu_'), 'fullname' => 'VTU User', 'email' => uniqid().'@example.com',
        'phone' => '08012345678', 'password' => 'password', 'wallet_balance' => 201,
    ]);
    $transaction = Transaction::create([
        'user_id' => $user->id, 'transaction_type' => 'data_subscription', 'provider' => 'vtu_ng',
        'amount' => 799, 'status' => 'pending', 'transaction_reference' => uniqid('TXN-'),
        'payment_reference' => uniqid('vendify_TXN-'), 'balance_before' => 1000, 'balance_after' => 201,
    ]);
    $vendor = new Vendor(['name' => 'VTU.ng', 'sub_category' => 'vtu_ng', 'base_url' => 'https://vtu.test/api/v2', 'api_key' => 'token', 'active' => true]);
    Http::fake(['https://vtu.test/api/v2/requery' => Http::response(['status' => $status, 'message' => $status])]);
    return [$user, $transaction, new VTUNg($vendor)];
}

it('records an asynchronous acknowledgement as pending without a failed notification', function () {
    [$user] = vtuNgFixture('processing-api');
    $recorded = \App\Classes\TransactionService::record([
        'transaction_type' => 'data_subscription', 'provider' => 'vtu_ng', 'amount' => 799,
        'discount_amount' => 0, 'status' => 'pending', 'transaction_reference' => uniqid('TXN-initial-'),
        'payment_reference' => uniqid('vendify_TXN-initial-'),
    ], $user, ['balance_before' => 1000, 'balance_after' => 201]);

    expect($recorded['status'])->toBe('pending');
    Notification::assertNothingSent();
});

it('formats electricity purchases for the VTU.ng v2 API', function () {
    $vendor = new Vendor([
        'name' => 'VTU.ng', 'sub_category' => 'vtu_ng',
        'base_url' => 'https://vtu.test/api/v2', 'api_key' => 'token', 'active' => true,
    ]);

    $payload = (new VTUNg($vendor))->formatPayload('electricity', [
        'tx_ref' => 'TXN-POWER-1',
        'meter_number' => '01234567890',
        'disco' => 'IKEDC (Ikeja Electric)',
        'meter_type' => 'postpaid',
        'amount' => 2500,
        'final_amount' => 2550,
    ]);

    expect($payload)->toBe([
        'request_id' => 'vendify_TXN-POWER-1',
        'customer_id' => '01234567890',
        'service_id' => 'ikeja-electric',
        'variation_id' => 'postpaid',
        'amount' => 2500,
    ]);
});

it('verifies electricity meters through VTU.ng', function () {
    $vendor = new Vendor([
        'name' => 'VTU.ng', 'sub_category' => 'vtu_ng',
        'base_url' => 'https://vtu.test/api/v2', 'api_key' => 'token', 'active' => true,
    ]);
    Http::fake([
        'https://vtu.test/api/v2/verify-customer' => Http::response([
            'code' => 'success',
            'message' => 'Customer Details Retrieved',
            'data' => [
                'customer_name' => 'Ada Customer',
                'customer_address' => '1 Power Street',
                'meter_number' => '01234567890',
                'min_purchase_amount' => 1000,
                'max_purchase_amount' => 100000,
            ],
        ]),
    ]);

    $response = (new VTUNg($vendor))->verifyUser('electricity', '01234567890', [
        'disco' => 'AEDC (Abuja Electric)',
        'meter_type' => 'prepaid',
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['data']['name'])->toBe('Ada Customer');
    Http::assertSent(fn ($request) => $request->url() === 'https://vtu.test/api/v2/verify-customer'
        && $request['customer_id'] === '01234567890'
        && $request['service_id'] === 'abuja-electric'
        && $request['variation_id'] === 'prepaid');
});

it('always routes electricity to the active VTU.ng provider', function () {
    $provider = Provider::create([
        'name' => 'VTU.ng', 'category' => 'vendor', 'sub_category' => 'vtu_ng',
        'base_url' => 'https://vtu.test/api/v2', 'api_key' => 'token', 'active' => true,
    ]);

    $handler = VTUServiceFactory::make('electricity', 'electricity', null, null, 'IKEDC (Ikeja Electric)');

    expect($handler)->toBeInstanceOf(VTUNg::class)
        ->and($handler->providerId())->toBe($provider->id);
});

it('keeps processing pending without refund or failure notification', function () {
    [$user, $transaction, $client] = vtuNgFixture('processing-api');
    expect($client->reconcile($transaction))->toBeFalse()
        ->and($transaction->fresh()->status)->toBe('pending')
        ->and((float) $user->fresh()->wallet_balance)->toBe(201.0);
    Notification::assertNothingSent();
});

it('settles processing to completion once', function () {
    [$user, $transaction, $client] = vtuNgFixture('completed-api');
    expect($client->reconcile($transaction))->toBeTrue();
    expect($client->reconcile($transaction->fresh()))->toBeTrue();
    expect($transaction->fresh()->status)->toBe('success')
        ->and((float) $user->fresh()->wallet_balance)->toBe(201.0)
        ->and($transaction->fresh()->completed_at)->not->toBeNull();
    Notification::assertSentToTimes($user, AppNotification::class, 1);
});

it('refunds a provider-refunded order exactly once', function () {
    [$user, $transaction, $client] = vtuNgFixture('refunded');
    $client->reconcile($transaction);
    $client->reconcile($transaction->fresh());
    expect($transaction->fresh()->status)->toBe('fail')
        ->and($transaction->fresh()->refunded_at)->not->toBeNull()
        ->and((float) $user->fresh()->wallet_balance)->toBe(1000.0);
    Notification::assertSentToTimes($user, AppNotification::class, 1);
});

it('manual recheck resolves an active provider row and settles completed order', function () {
    [, $transaction] = vtuNgFixture('completed-api');
    Provider::create([
        'name' => 'VTU.ng', 'sub_category' => 'vtu_ng', 'category' => null,
        'base_url' => 'https://vtu.test/api/v2', 'api_key' => 'token', 'active' => true,
    ]);

    $response = (new TransactionController)->recheckProvider($transaction->id);

    expect($response->status())->toBe(200)
        ->and($transaction->fresh()->status)->toBe('success');
});

it('inactive provider still produces the accurate manual recheck error', function () {
    [, $transaction] = vtuNgFixture('completed-api');
    Provider::create([
        'name' => 'VTU.ng', 'sub_category' => 'vtu_ng', 'category' => 'vendor',
        'base_url' => 'https://vtu.test/api/v2', 'api_key' => 'token', 'active' => false,
    ]);

    $response = (new TransactionController)->recheckProvider($transaction->id);

    expect($response->status())->toBe(422)
        ->and($response->getData(true)['message'])->toBe('The VTU.ng provider is not active.')
        ->and($transaction->fresh()->status)->toBe('pending');
});

it('background reconciliation uses the shared active provider lookup', function () {
    [, $transaction] = vtuNgFixture('completed-api');
    Provider::create([
        'name' => 'VTU NG', 'sub_category' => 'VTU.NG', 'category' => null,
        'base_url' => 'https://vtu.test/api/v2', 'api_key' => 'token', 'active' => true,
    ]);

    (new ReconcileVTUNgTransaction($transaction->id))->handle();

    expect($transaction->fresh()->status)->toBe('success');
});

it('persists the vendor connection switch to the raw active field used by reconciliation', function () {
    $provider = Provider::create([
        'name' => 'VTU.ng', 'sub_category' => 'vtu_ng', 'category' => 'vendor',
        'base_url' => 'https://vtu.test/api/v2', 'api_key' => 'token', 'active' => false,
    ]);

    $request = Request::create('/api/table/vendors/'.$provider->id, 'PUT', [
        'connection' => true,
    ]);
    $response = AdminController::universalCreateOrUpdate($request, 'vendors', $provider->id);

    $resource = (new VendorResource(
        Vendor::withoutGlobalScopes()->findOrFail($provider->id),
    ))->resolve();

    expect($response->status())->toBe(200)
        ->and((bool) Provider::withoutGlobalScopes()->findOrFail($provider->id)->getRawOriginal('active'))->toBeTrue()
        ->and(VTUNg::activeProvider()?->id)->toBe($provider->id)
        ->and($resource['active'])->toBeTrue();
});
