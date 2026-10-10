<?php

use App\Classes\Payment\Provider\PaymentPoint;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function paymentPointGateway(array $attributes = []): PaymentPoint
{
    return new PaymentPoint(new Provider(array_merge([
        'name' => 'payment point',
        'username' => 'business-id',
        'password' => 'api-token',
        'api_key' => 'public-api-key',
        'webhook_access' => 'webhook-secret',
    ], $attributes)));
}

it('creates a virtual account with documented headers and PalmPay only by default', function () {
    Log::spy();
    Http::fake([
        'api.paymentpoint.co/*' => Http::response([
            'status' => 'success',
            'bankAccounts' => [[
                'accountNumber' => '6698059290',
                'accountName' => 'Test (PaymentPoint)',
                'bankName' => 'PalmPay',
                'Reserved_Account_Id' => 'reserved-id',
            ]],
        ]),
    ]);

    $user = new User([
        'email' => 'customer@example.com',
        'username' => 'Test Customer',
        'fullname' => 'Test Customer Fullname',
        'phone' => '08012345678',
    ]);
    $user->id = 10;

    $account = paymentPointGateway()->generate($user);

    expect($account['bank_account'])->toBe('6698059290');

    Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context): bool => $event === 'funding_account.provider_response'
        && $context['usable_bank_account_count'] === 1
        && $context['failure_category'] === 'none'
    )->once();

    Http::assertSent(function (ClientRequest $request) {
        return $request->url() === 'https://api.paymentpoint.co/api/v1/createVirtualAccount'
            && $request->hasHeader('Authorization', 'Bearer api-token')
            && $request->hasHeader('api-key', 'public-api-key')
            && $request['businessId'] === 'business-id'
            && $request['name'] === 'Test Customer Fullname'
            && $request['bankCode'] === ['20946'];
    });
});

it('can restore an available configured partner bank without a code change', function () {
    config()->set('paymentpoint.virtual_account_bank_codes', ['20946', '20897']);
    Http::fake([
        'api.paymentpoint.co/*' => Http::response([
            'status' => 'success',
            'bankAccounts' => [[
                'accountNumber' => '6698059290',
                'bankName' => 'PalmPay',
            ]],
        ]),
    ]);

    $user = new User([
        'email' => 'customer@example.com',
        'username' => 'customer',
        'fullname' => 'Test Customer',
        'phone' => '08012345678',
    ]);
    $user->id = 10;

    expect(paymentPointGateway()->generate($user))->not->toBeNull();
    Http::assertSent(fn (ClientRequest $request) => $request['bankCode'] === ['20946', '20897']);
});

it('rejects a successful HTTP response that has no usable bank account', function () {
    Http::fake([
        'api.paymentpoint.co/*' => Http::response([
            'status' => 'success',
            'message' => 'Processed',
            'bankAccounts' => [[
                'accountNumber' => '',
                'bankName' => '',
            ]],
        ]),
    ]);

    $user = new User([
        'email' => 'customer@example.com',
        'username' => 'customer',
        'fullname' => 'Test Customer',
        'phone' => '08012345678',
    ]);
    $user->id = 10;

    expect(paymentPointGateway()->generate($user))->toBeNull();
});

it('rejects an unexpected OPay account while PalmPay is the only requested bank', function () {
    Http::fake([
        'api.paymentpoint.co/*' => Http::response([
            'status' => 'success',
            'bankAccounts' => [[
                'accountNumber' => '6698059290',
                'bankName' => 'OPay',
                'bankCode' => '20897',
            ]],
        ]),
    ]);

    $user = new User([
        'email' => 'customer@example.com',
        'username' => 'customer',
        'fullname' => 'Test Customer',
        'phone' => '08012345678',
    ]);
    $user->id = 10;

    expect(paymentPointGateway()->generate($user))->toBeNull();
});

it('logs only redacted structured diagnostics for an unusable provider response', function () {
    Log::spy();
    Http::fake([
        'api.paymentpoint.co/*' => Http::response([
            'status' => 'success',
            'message' => 'Sensitive provider message for customer@example.com',
            'customer' => [
                'customer_id' => 'sensitive-customer-id',
                'customer_email' => 'customer@example.com',
            ],
            'bankAccounts' => [],
            'errors' => [[
                'code' => 'BANK_ACCOUNT_CREATION_FAILED',
                'category' => 'bank_generation',
                'field' => 'bankCode',
                'bankCode' => '20946',
                'message' => 'Sensitive bank error for customer@example.com',
            ]],
        ], 201),
    ]);

    $user = new User([
        'email' => 'customer@example.com',
        'username' => 'customer',
        'fullname' => 'Test Customer',
        'phone' => '08012345678',
    ]);
    $user->id = 10;

    expect(paymentPointGateway()->generate($user))->toBeNull();

    Log::shouldHaveReceived('info')->withArgs(function (string $event, array $context): bool {
        return $event === 'funding_account.provider_response'
            && $context['customer_record_present'] === true
            && $context['customer_id_present'] === true
            && $context['error_codes'] === ['BANK_ACCOUNT_CREATION_FAILED']
            && $context['error_categories'] === ['bank_generation']
            && $context['error_fields'] === ['bank_code']
            && $context['error_bank_codes'] === ['20946']
            && $context['failure_category'] === 'customer_created_bank_generation_failed'
            && ! str_contains(json_encode($context), 'customer@example.com')
            && ! str_contains(json_encode($context), 'Sensitive provider message')
            && ! str_contains(json_encode($context), 'Sensitive bank error')
            && ! str_contains(json_encode($context), 'sensitive-customer-id');
    })->once();
});

it('does not promote free-form provider errors into diagnostic fields', function () {
    Log::spy();
    Http::fake([
        'api.paymentpoint.co/*' => Http::response([
            'status' => 'success',
            'bankAccounts' => [],
            'errors' => ['Customer customer@example.com could not be provisioned'],
        ], 201),
    ]);

    $user = new User([
        'email' => 'customer@example.com',
        'username' => 'customer',
        'fullname' => 'Test Customer',
        'phone' => '08012345678',
    ]);
    $user->id = 10;

    expect(paymentPointGateway()->generate($user))->toBeNull();

    Log::shouldHaveReceived('info')->withArgs(fn (string $event, array $context): bool => $event === 'funding_account.provider_response'
        && $context['error_item_types'] === ['string']
        && $context['error_codes'] === []
        && $context['error_categories'] === []
        && $context['error_fields'] === []
        && ! str_contains(json_encode($context), 'customer@example.com')
    )->once();
});

it('does not call PaymentPoint when a required credential is still a placeholder', function () {
    Http::fake();

    $user = new User([
        'email' => 'customer@example.com',
        'username' => 'customer',
        'fullname' => 'Test Customer',
        'phone' => '08012345678',
    ]);
    $user->id = 10;

    expect(paymentPointGateway(['username' => '#'])->generate($user))->toBeNull();
    Http::assertNothingSent();
});

it('reads the documented receiver account number from a webhook', function () {
    $request = Request::create('/webhook', 'POST', [
        'receiver' => ['account_number' => '6679854996'],
    ]);

    expect(paymentPointGateway()->virtualAccountNumber($request))->toBe('6679854996');
});

it('verifies the documented Paymentpoint-Signature header', function () {
    $body = json_encode(['transaction_id' => 'tx-123', 'amount_paid' => 100]);
    $signature = hash_hmac('sha256', $body, 'webhook-secret');
    $request = Request::create('/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PAYMENTPOINT_SIGNATURE' => $signature,
    ], $body);

    $method = new ReflectionMethod(PaymentPoint::class, 'verifyWebhookSignature');

    expect($method->invoke(paymentPointGateway(), $request))->toBeTrue();
});

it('rejects webhook signatures when the secret is still the database placeholder', function () {
    $body = json_encode(['transaction_id' => 'tx-123', 'amount_paid' => 100]);
    $request = Request::create('/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PAYMENTPOINT_SIGNATURE' => hash_hmac('sha256', $body, '1'),
    ], $body);

    $method = new ReflectionMethod(PaymentPoint::class, 'verifyWebhookSignature');

    expect($method->invoke(paymentPointGateway(['webhook_access' => '1']), $request))->toBeFalse();
});
