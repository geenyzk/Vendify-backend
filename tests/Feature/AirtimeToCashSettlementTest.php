<?php

use App\Http\Controllers\AirtimeToCashController;
use App\Models\AirtimeToCashRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AirtimeToCashSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function airtimeToCashFixture(string $status = 'pending'): array
{
    $user = User::factory()->create([
        'username' => 'atc-user-'.Str::lower(Str::random(8)),
        'fullname' => 'Airtime Customer',
        'phone' => '080'.random_int(10000000, 99999999),
        'status' => 'active',
        'wallet_balance' => 1000,
    ]);
    $reviewer = User::factory()->create([
        'username' => 'atc-reviewer-'.Str::lower(Str::random(8)),
        'fullname' => 'Airtime Reviewer',
        'phone' => '081'.random_int(10000000, 99999999),
        'status' => 'active',
    ]);
    $request = AirtimeToCashRequest::create([
        'user_id' => $user->id,
        'network' => 'mtn',
        'amount' => 1000,
        'sender_phone' => '08010000000',
        'destination_number' => '08020000000',
        'payout_amount' => 950,
        'status' => $status,
        'transaction_reference' => 'ATC-'.Str::upper(Str::random(16)),
    ]);

    return [$user, $reviewer, $request];
}

test('normal settlement credits once and links a dedicated payout transaction', function () {
    [$user, $reviewer, $request] = airtimeToCashFixture();

    $settled = app(AirtimeToCashSettlementService::class)->settle($request->id, $reviewer->id);
    $transaction = $settled->payoutTransaction;

    expect((float) $user->fresh()->wallet_balance)->toBe(1950.0)
        ->and($settled->status)->toBe('approved')
        ->and((string) $settled->reviewed_by)->toBe((string) $reviewer->id)
        ->and($settled->payout_transaction_reference)->toBe($transaction->transaction_reference)
        ->and($transaction->transaction_type)->toBe(Transaction::TYPE_AIRTIME_TO_CASH)
        ->and($transaction->airtime_to_cash_request_id)->toBe($request->id)
        ->and($transaction->airtimeToCashRequest->is($settled))->toBeTrue();
});

test('the existing approval controller contract delegates to atomic settlement', function () {
    [$user, $reviewer, $request] = airtimeToCashFixture();
    Auth::login($reviewer);

    $response = app(AirtimeToCashController::class)->approve(
        $request,
        app(AirtimeToCashSettlementService::class),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['message'])->toBe('Request approved and wallet credited')
        ->and($request->fresh()->status)->toBe('approved')
        ->and((float) $user->fresh()->wallet_balance)->toBe(1950.0);
});

test('repeated settlement never credits the wallet twice', function () {
    [$user, $reviewer, $request] = airtimeToCashFixture();
    $service = app(AirtimeToCashSettlementService::class);

    $service->settle($request->id, $reviewer->id);

    expect(fn () => $service->settle($request->id, $reviewer->id))
        ->toThrow(\DomainException::class, 'already been reviewed');

    expect((float) $user->fresh()->wallet_balance)->toBe(1950.0)
        ->and(Transaction::where('airtime_to_cash_request_id', $request->id)->count())->toBe(1);
});

test('database uniqueness prevents a second payout ledger for one conversion', function () {
    [$user, $reviewer, $request] = airtimeToCashFixture();
    app(AirtimeToCashSettlementService::class)->settle($request->id, $reviewer->id);

    expect(fn () => Transaction::create([
        'user_id' => $user->id,
        'transaction_type' => Transaction::TYPE_AIRTIME_TO_CASH,
        'amount' => 950,
        'status' => 'success',
        'transaction_reference' => 'ATC-DUPLICATE-'.Str::upper(Str::random(8)),
        'airtime_to_cash_request_id' => $request->id,
    ]))->toThrow(Throwable::class);

    expect(Transaction::where('airtime_to_cash_request_id', $request->id)->count())->toBe(1)
        ->and((float) $user->fresh()->wallet_balance)->toBe(1950.0);
});

test('a failure after ledger creation rolls back ledger wallet and request together', function () {
    [$user, $reviewer, $request] = airtimeToCashFixture();
    $service = new class extends AirtimeToCashSettlementService {
        protected function afterPayoutCreated(AirtimeToCashRequest $request, Transaction $transaction): void
        {
            throw new RuntimeException('forced settlement failure');
        }
    };

    expect(fn () => $service->settle($request->id, $reviewer->id))
        ->toThrow(RuntimeException::class, 'forced settlement failure');

    expect((float) $user->fresh()->wallet_balance)->toBe(1000.0)
        ->and($request->fresh()->status)->toBe('pending')
        ->and($request->fresh()->payout_transaction_reference)->toBeNull()
        ->and(Transaction::where('airtime_to_cash_request_id', $request->id)->count())->toBe(0);
});

test('a rejected request cannot later be approved', function () {
    [$user, $reviewer, $request] = airtimeToCashFixture();
    Auth::login($reviewer);

    $response = app(AirtimeToCashController::class)->reject(
        Request::create('/', 'POST', ['reason' => 'Transfer not received']),
        $request,
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($request->fresh()->status)->toBe('rejected');

    expect(fn () => app(AirtimeToCashSettlementService::class)->settle($request->id, $reviewer->id))
        ->toThrow(\DomainException::class, 'already been reviewed');

    expect((float) $user->fresh()->wallet_balance)->toBe(1000.0)
        ->and(Transaction::where('airtime_to_cash_request_id', $request->id)->count())->toBe(0);
});

test('an approved request cannot later be rejected', function () {
    [$user, $reviewer, $request] = airtimeToCashFixture();
    Auth::login($reviewer);
    app(AirtimeToCashSettlementService::class)->settle($request->id, $reviewer->id);

    $response = app(AirtimeToCashController::class)->reject(
        Request::create('/', 'POST', ['reason' => 'Too late']),
        $request->fresh(),
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($request->fresh()->status)->toBe('approved')
        ->and((float) $user->fresh()->wallet_balance)->toBe(1950.0)
        ->and(Transaction::where('airtime_to_cash_request_id', $request->id)->count())->toBe(1);
});

test('existing customer and admin history actions still list conversion requests', function () {
    [$user, $reviewer, $request] = airtimeToCashFixture();
    $other = AirtimeToCashRequest::create([
        'user_id' => $reviewer->id,
        'network' => 'glo',
        'amount' => 500,
        'sender_phone' => '08030000000',
        'destination_number' => '08040000000',
        'payout_amount' => 475,
        'status' => 'pending',
        'transaction_reference' => 'ATC-'.Str::upper(Str::random(16)),
    ]);
    $controller = app(AirtimeToCashController::class);

    Auth::login($user);
    $customerData = $controller->myRequests()->getData(true)['data'];
    $adminData = $controller->adminIndex()->getData(true)['data'];

    expect(collect($customerData)->pluck('id')->all())->toBe([$request->id])
        ->and(collect($adminData)->pluck('id')->all())->toContain($request->id, $other->id);
});
