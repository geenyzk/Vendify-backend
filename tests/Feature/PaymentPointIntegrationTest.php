<?php

use App\Classes\Payment\Provider\PaymentPoint;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

it('creates a virtual account with documented headers and both partner banks', function () {
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
        'phone' => '08012345678',
    ]);
    $user->id = 10;

    $account = paymentPointGateway()->generate($user);

    expect($account['bank_account'])->toBe('6698059290');

    Http::assertSent(function (ClientRequest $request) {
        return $request->url() === 'https://api.paymentpoint.co/api/v1/createVirtualAccount'
            && $request->hasHeader('Authorization', 'Bearer api-token')
            && $request->hasHeader('api-key', 'public-api-key')
            && $request['businessId'] === 'business-id'
            && $request['bankCode'] === ['20946', '20897'];
    });
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
