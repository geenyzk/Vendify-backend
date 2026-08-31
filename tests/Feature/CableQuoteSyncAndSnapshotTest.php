<?php

use App\Classes\Vendor\Providers\VTUNg;
use App\Models\CablePlan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Cable\CablePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function cableRole(string $slug, bool $staff, array $permissions = []): Role
{
    // firstOrCreate, not create: a test that makes two customers would
    // otherwise collide on the shared 'user' role slug.
    $role = Role::firstOrCreate(
        ['slug' => $slug],
        ['name' => ucfirst($slug), 'is_staff' => $staff, 'is_active' => true],
    );
    $role->permissions()->sync(collect($permissions)->map(
        fn ($p) => Permission::firstOrCreate(['slug' => $p], ['name' => ucwords(str_replace('_', ' ', $p))])->id,
    ));

    return $role;
}

function cableUser(string $suffix, ?Role $role = null): User
{
    return User::create([
        'username' => "cable_{$suffix}",
        'fullname' => "Cable {$suffix}",
        'email' => "cable_{$suffix}@example.test",
        'phone' => '070'.str_pad((string) abs(crc32("cable_{$suffix}")), 8, '0', STR_PAD_LEFT),
        'password' => 'password',
        'status' => 'active',
        'role_id' => ($role ?? cableRole('user', false))->id,
    ]);
}

function cableVendorFor(string $name, string $subCategory, bool $active = true): Vendor
{
    return Vendor::create([
        'name' => $name,
        'sub_category' => $subCategory,
        'base_url' => 'https://vtu.test/api/v2',
        'api_key' => 'test-token',
        'active' => $active,
    ]);
}

function cablePlanWithFee(array $chargeFee = [], string $service = 'dstv', string $name = 'Compact'): CablePlan
{
    return CablePlan::create([
        'cable_network' => $service,
        'plan_name' => $name,
        'active' => true,
        'charge_fee' => $chargeFee,
    ]);
}

function mapCablePlan(
    CablePlan $plan,
    Vendor $vendor,
    float $cost,
    array $overrides = [],
): void {
    DB::table('providerables')->insert(array_merge([
        'provider_id' => $vendor->id,
        'providerable_id' => $plan->id,
        'providerable_type' => CablePlan::class,
        'external_plan_id' => '2701',
        'server_id' => '2701',
        'provider_service_id' => $plan->cable_network,
        'provider_plan_name' => $plan->plan_name,
        'cost_price' => $cost,
        'provider_price' => $cost,
        'margin_value' => 0,
        'margin_type' => 'fiat',
        'provider_available' => true,
        'provider_enabled' => true,
        'priority' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function verifyCableDecoder(User $user, string $service, string $identifier, float $renewalAmount): void
{
    Cache::put(
        'cable-verification:'.$user->id.':'.$service.':'.hash('sha256', $identifier),
        ['verified' => true, 'renewal_amount' => $renewalAmount, 'customer_name' => 'ADAEZE OKONKWO'],
        now()->addMinutes(10),
    );
}

/* ── 1–4. Admin cable sync endpoint ──────────────────────────────────────── */

test('cable sync endpoint is refused to a customer and allowed to settings staff', function () {
    Http::fake(['*/variations/tv' => Http::response(['data' => []])]);
    $vendor = cableVendorFor('VTU.ng', 'vtung');

    Sanctum::actingAs(cableUser('customer'));
    $this->postJson("/api/admin/vendor/{$vendor->id}/sync-cable-plans")->assertForbidden();

    Sanctum::actingAs(cableUser('ops', cableRole('owner', true, ['settings'])));
    $this->postJson("/api/admin/vendor/{$vendor->id}/sync-cable-plans")->assertOk();
});

test('cable sync endpoint runs the provider sync and reports its counts', function () {
    Http::fake(['*/variations/tv' => Http::response(['data' => [[
        'variation_id' => 2701, 'service_id' => 'dstv', 'service_name' => 'DStv',
        'package_bouquet' => 'Compact', 'price' => '19000', 'availability' => 'Available',
    ]]])]);
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    Sanctum::actingAs(cableUser('ops', cableRole('owner', true, ['settings'])));

    $response = $this->postJson("/api/admin/vendor/{$vendor->id}/sync-cable-plans")->assertOk();

    // Same summary shape the CLI prints, because both call syncCablePlans().
    expect($response->json('data.fetched'))->toBe(1)
        ->and($response->json('data.created'))->toBe(1)
        ->and($response->json('data.unavailable'))->toBe(0)
        ->and(CablePlan::where('cable_network', 'dstv')->where('plan_name', 'Compact')->exists())->toBeTrue();
    Http::assertSentCount(1);
});

test('cable sync leaves a manual CheapDataHub mapping untouched', function () {
    Http::fake(['*/variations/tv' => Http::response(['data' => [[
        'variation_id' => 2701, 'service_id' => 'dstv', 'service_name' => 'DStv',
        'package_bouquet' => 'Compact', 'price' => '19000', 'availability' => 'Available',
    ]]])]);
    $vtu = cableVendorFor('VTU.ng', 'vtung');
    $cdh = cableVendorFor('CheapDataHub', 'cheapdatahub');
    $plan = cablePlanWithFee([], 'dstv', 'Compact');
    // A hand-entered mapping: no external_plan_id, its own cost and plan id.
    mapCablePlan($plan, $cdh, 17500, ['external_plan_id' => null, 'server_id' => '44', 'priority' => 5]);

    Sanctum::actingAs(cableUser('ops', cableRole('owner', true, ['settings'])));
    $this->postJson("/api/admin/vendor/{$vtu->id}/sync-cable-plans")->assertOk();

    $manual = DB::table('providerables')
        ->where('providerable_type', CablePlan::class)
        ->where('provider_id', $cdh->id)
        ->first();

    expect($manual)->not->toBeNull()
        ->and((float) $manual->cost_price)->toBe(17500.0)
        ->and($manual->server_id)->toBe('44')
        ->and($manual->external_plan_id)->toBeNull();
});

test('cable sync refuses to run for a disabled provider and changes nothing', function () {
    Http::fake();
    $vendor = cableVendorFor('VTU.ng', 'vtung', active: false);
    Sanctum::actingAs(cableUser('ops', cableRole('owner', true, ['settings'])));

    $this->postJson("/api/admin/vendor/{$vendor->id}/sync-cable-plans")
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($vendor->fresh()->active)->toBeFalse()
        ->and(CablePlan::count())->toBe(0);
    Http::assertNothingSent();
});

/* ── 5–8. Quote pricing ──────────────────────────────────────────────────── */

test('renew quote adds the role fee to the decoder renewal amount exactly once', function () {
    $user = cableUser('renewer');
    Sanctum::actingAs($user);
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    $plan = cablePlanWithFee(['user' => ['type' => 'fiat', 'value' => 500]]);
    mapCablePlan($plan, $vendor, 19000);
    verifyCableDecoder($user, 'dstv', '1234567890', 18500);

    $response = $this->postJson('/api/customer/cable/quote', [
        'cable_plan' => $plan->id, 'subscription_type' => 'renew', 'iuc' => '1234567890',
    ])->assertOk();

    // The fee applies to the RENEWAL amount, not to the catalogue package.
    expect($response->json('data.base_amount'))->toBe(18500.0)
        ->and($response->json('data.service_fee'))->toBe(500.0)
        ->and($response->json('data.total_amount'))->toBe(19000.0)
        ->and($response->json('data.currency'))->toBe('NGN');
});

test('a zero role fee makes the renew total exactly the renewal amount', function () {
    $user = cableUser('nofee');
    Sanctum::actingAs($user);
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    $plan = cablePlanWithFee(['user' => ['type' => 'fiat', 'value' => 0]]);
    mapCablePlan($plan, $vendor, 19000);
    verifyCableDecoder($user, 'dstv', '1234567890', 18500);

    $response = $this->postJson('/api/customer/cable/quote', [
        'cable_plan' => $plan->id, 'subscription_type' => 'renew', 'iuc' => '1234567890',
    ])->assertOk();

    expect($response->json('data.service_fee'))->toBe(0.0)
        ->and($response->json('data.total_amount'))->toBe(18500.0);
});

test('a change quote is the same number the purchase path would debit', function () {
    $user = cableUser('changer');
    Sanctum::actingAs($user);
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    $plan = cablePlanWithFee(['user' => ['type' => 'percentage', 'value' => 2]]);
    mapCablePlan($plan, $vendor, 19000);

    $quoted = $this->postJson('/api/customer/cable/quote', [
        'cable_plan' => $plan->id, 'subscription_type' => 'change',
    ])->assertOk()->json('data.total_amount');

    // VTUServicesController::handle() resolves the charge through total().
    $charged = app(CablePricingService::class)->total($plan->fresh(), 'change', null, $user);

    expect($quoted)->toBe(19380.0)->and($charged)->toBe($quoted);
});

test('a client-supplied amount cannot influence the quote', function () {
    $user = cableUser('spoofer');
    Sanctum::actingAs($user);
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    $plan = cablePlanWithFee(['user' => ['type' => 'fiat', 'value' => 500]]);
    mapCablePlan($plan, $vendor, 19000);

    $response = $this->postJson('/api/customer/cable/quote', [
        'cable_plan' => $plan->id,
        'subscription_type' => 'change',
        // All ignored: the resolver reads the mapping and the role fee only.
        'amount' => 1, 'total_amount' => 1, 'service_fee' => 0, 'renewal_amount' => 1,
    ])->assertOk();

    expect($response->json('data.total_amount'))->toBe(19500.0);
});

test('renew is quoted only for a decoder this user actually verified', function () {
    $user = cableUser('unverified');
    Sanctum::actingAs($user);
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    $plan = cablePlanWithFee(['user' => ['type' => 'fiat', 'value' => 500]]);
    mapCablePlan($plan, $vendor, 19000);
    verifyCableDecoder($user, 'dstv', '1234567890', 18500);

    // A different decoder on the same service must not inherit the amount.
    $this->postJson('/api/customer/cable/quote', [
        'cable_plan' => $plan->id, 'subscription_type' => 'renew', 'iuc' => '9999999999',
    ])->assertStatus(422);
});

test('renew is refused for a service the backend does not support renewing', function () {
    $user = cableUser('startimes');
    Sanctum::actingAs($user);
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    $plan = cablePlanWithFee([], 'startimes', 'Classic');
    mapCablePlan($plan, $vendor, 5000);
    verifyCableDecoder($user, 'startimes', '1234567890', 5000);

    $this->postJson('/api/customer/cable/quote', [
        'cable_plan' => $plan->id, 'subscription_type' => 'renew', 'iuc' => '1234567890',
    ])->assertStatus(422);
});

/* ── 7 (routing/pricing agreement). ──────────────────────────────────────── */

test('pricing follows the mapping that would actually serve the sale', function () {
    $user = cableUser('priced');
    Sanctum::actingAs($user);
    $primary = cableVendorFor('VTU.ng', 'vtung');
    $fallback = cableVendorFor('CheapDataHub', 'cheapdatahub');
    $plan = cablePlanWithFee();

    // Cheaper row first by insertion order, but it is switched off. Routing
    // skips it; pricing must skip it too rather than quoting 10,000.
    mapCablePlan($plan, $primary, 10000, ['provider_enabled' => false, 'priority' => 1]);
    mapCablePlan($plan, $fallback, 19000, ['external_plan_id' => '44', 'server_id' => '44', 'priority' => 2]);

    expect(app(CablePricingService::class)->baseAmount($plan->fresh()))->toBe(19000.0);
});

test('pricing prefers the highest-priority live mapping, not database row order', function () {
    $user = cableUser('priority');
    Sanctum::actingAs($user);
    $first = cableVendorFor('CheapDataHub', 'cheapdatahub');
    $second = cableVendorFor('VTU.ng', 'vtung');
    $plan = cablePlanWithFee();

    mapCablePlan($plan, $first, 21000, ['external_plan_id' => null, 'server_id' => '44', 'priority' => 9]);
    mapCablePlan($plan, $second, 19000, ['priority' => 1]);

    expect(app(CablePricingService::class)->baseAmount($plan->fresh()))->toBe(19000.0);
});

/* ── 9–11. Transaction snapshot ──────────────────────────────────────────── */

test('a cable transaction reports the service and package it was bought as', function () {
    $user = cableUser('buyer');
    $plan = cablePlanWithFee([], 'dstv', 'Compact');

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'transaction_type' => 'cable_subscription',
        'provider' => 'VTU.ng',
        'amount' => 19500,
        'status' => 'success',
        'transaction_reference' => 'VDFCB-SNAP-1',
        'receiver' => '1234567890',
        'account_or_phone' => '1234567890',
        'plan_type' => 'change',
        'raw_payload' => [
            'cable_service' => 'dstv',
            'cable_service_name' => 'DStv',
            'cable_package_name' => 'Compact',
            'cable_plan_id' => $plan->id,
            'cable_identifier' => '1234567890',
            'cable_subscription_type' => 'change',
        ],
    ]);

    expect($transaction->cable_service_name)->toBe('DStv')
        ->and($transaction->cable_package_name)->toBe('Compact')
        ->and($transaction->cable_identifier)->toBe('1234567890')
        ->and($transaction->cable_subscription_type)->toBe('change');
});

test('a renamed or deleted plan does not rewrite what a past receipt says', function () {
    $user = cableUser('historian');
    $plan = cablePlanWithFee([], 'dstv', 'Compact');

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'transaction_type' => 'cable_subscription',
        'provider' => 'VTU.ng',
        'amount' => 19500,
        'status' => 'success',
        'transaction_reference' => 'VDFCB-SNAP-2',
        'receiver' => '1234567890',
        'plan_type' => 'change',
        'raw_payload' => [
            'cable_service_name' => 'DStv',
            'cable_package_name' => 'Compact',
            'cable_plan_id' => $plan->id,
        ],
    ]);

    $plan->update(['plan_name' => 'Compact (2027 pricing)']);
    $plan->delete();

    expect($transaction->fresh()->cable_package_name)->toBe('Compact');
});

test('a non-cable transaction exposes no cable snapshot fields', function () {
    $transaction = Transaction::create([
        'user_id' => cableUser('dataonly')->id,
        'transaction_type' => 'data_subscription',
        'provider' => 'VTU.ng',
        'amount' => 500,
        'status' => 'success',
        'transaction_reference' => 'VDFDT-1',
        'raw_payload' => ['cable_package_name' => 'Compact'],
    ]);

    expect($transaction->cable_package_name)->toBeNull()
        ->and($transaction->cable_service_name)->toBeNull();
});

/* ── 12–13. Provider exposure ────────────────────────────────────────────── */

test('a customer reading their own cable transaction is told nothing about the vendor', function () {
    $user = cableUser('privacy');
    $transaction = Transaction::create([
        'user_id' => $user->id,
        'transaction_type' => 'cable_subscription',
        'provider' => 'VTU.ng',
        'amount' => 19500,
        'status' => 'success',
        'transaction_reference' => 'VDFCB-PRIV-1',
        'receiver' => '1234567890',
        'plan_type' => 'change',
        'raw_payload' => ['cable_service_name' => 'DStv', 'cable_package_name' => 'Compact'],
    ]);

    Sanctum::actingAs($user);
    $response = $this->getJson("/api/transactions/{$transaction->id}/status")->assertOk();

    expect($response->json('data.provider'))->toBeNull()
        ->and($response->json('data'))->not->toHaveKey('provider_key')
        ->and($response->json('data'))->not->toHaveKey('primary_provider_id')
        ->and($response->json('data'))->not->toHaveKey('final_provider_id')
        ->and(json_encode($response->json()))->not->toContain('VTU.ng');
});

test('the customer dashboard list also withholds the vendor name', function () {
    $user = cableUser('dashboard');
    Transaction::create([
        'user_id' => $user->id,
        'transaction_type' => 'cable_subscription',
        'provider' => 'VTU.ng',
        'amount' => 19500,
        'status' => 'success',
        'transaction_reference' => 'VDFCB-PRIV-2',
        'receiver' => '1234567890',
        'plan_type' => 'change',
        'raw_payload' => ['cable_service_name' => 'DStv', 'cable_package_name' => 'Compact'],
    ]);

    Sanctum::actingAs($user);
    $response = $this->getJson('/api/customer/dashboard')->assertOk();

    expect(json_encode($response->json('data.transactions')))->not->toContain('VTU.ng');
});

test('staff can still see which vendor served a transaction', function () {
    $customer = cableUser('served');
    $transaction = Transaction::create([
        'user_id' => $customer->id,
        'transaction_type' => 'cable_subscription',
        'provider' => 'VTU.ng',
        'amount' => 19500,
        'status' => 'pending',
        'transaction_reference' => 'VDFCB-ADMIN-1',
        'receiver' => '1234567890',
        'plan_type' => 'change',
    ]);

    Sanctum::actingAs(cableUser('reconciler', cableRole('owner', true, ['transactions'])));
    $response = $this->putJson("/api/admin/transactions/{$transaction->id}/status", [
        'status' => 'fail',
    ])->assertOk();

    expect($response->json('data.provider'))->toBe('VTU.ng')
        ->and($response->json('data.provider_key'))->toBe('VTU.ng');
});

/* ── 14. Pivot safety ────────────────────────────────────────────────────── */

test('cable provider mappings load their sync metadata without leaking credentials', function () {
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    // Both are $hidden on the model and must never ride along on a pivot.
    $vendor->update(['password' => 'super-secret', 'api_key' => 'sk_live_secret']);
    $plan = cablePlanWithFee();
    mapCablePlan($plan, $vendor, 19000, ['last_synced_at' => now()]);

    Sanctum::actingAs(cableUser('ops', cableRole('owner', true, ['settings'])));
    $response = $this->getJson("/api/table/cable_plans/{$plan->id}")->assertOk();

    $pivot = $plan->fresh()->provider->pivot;
    expect($pivot->external_plan_id)->toBe('2701')
        ->and($pivot->provider_service_id)->toBe('dstv')
        ->and((bool) $pivot->provider_enabled)->toBeTrue()
        ->and((bool) $pivot->provider_available)->toBeTrue()
        ->and($pivot->last_synced_at)->not->toBeNull()
        ->and(json_encode($response->json()))->not->toContain('super-secret')
        ->and(json_encode($response->json()))->not->toContain('sk_live_secret');
});

/* ── 15–17. Verification cache key ───────────────────────────────────────── */

test('a verified decoder stays verified when the customer picks another package', function () {
    $user = cableUser('switcher');
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    $compact = cablePlanWithFee([], 'dstv', 'Compact');
    $premium = cablePlanWithFee([], 'dstv', 'Premium');
    mapCablePlan($compact, $vendor, 19000);
    mapCablePlan($premium, $vendor, 44500, ['external_plan_id' => '2702', 'server_id' => '2702']);
    verifyCableDecoder($user, 'dstv', '1234567890', 18500);

    // The cache key is (user, service, identifier) — the bouquet is not part
    // of it, so changing package must not cost the customer a re-verification.
    expect(VTUNg::verifiedCableCustomer($user->id, 'dstv', '1234567890'))->not->toBeNull();

    Sanctum::actingAs($user);
    $this->postJson('/api/customer/cable/quote', [
        'cable_plan' => $premium->id, 'subscription_type' => 'renew', 'iuc' => '1234567890',
    ])->assertOk()->assertJsonPath('data.base_amount', 18500.0);
});

test('a different decoder is not covered by an existing verification', function () {
    $user = cableUser('otherdecoder');
    verifyCableDecoder($user, 'dstv', '1234567890', 18500);

    expect(VTUNg::verifiedCableCustomer($user->id, 'dstv', '9999999999'))->toBeNull();
});

test('a verification on one service does not carry over to another', function () {
    $user = cableUser('otherservice');
    verifyCableDecoder($user, 'dstv', '1234567890', 18500);

    expect(VTUNg::verifiedCableCustomer($user->id, 'gotv', '1234567890'))->toBeNull();
});

test('one customer cannot inherit another customer verification', function () {
    $owner = cableUser('owner-of-decoder');
    $other = cableUser('stranger');
    verifyCableDecoder($owner, 'dstv', '1234567890', 18500);

    expect(VTUNg::verifiedCableCustomer($other->id, 'dstv', '1234567890'))->toBeNull();
});

/* ── 18. Showmax ─────────────────────────────────────────────────────────── */

test('Showmax verification answers without contacting the provider', function () {
    Http::fake();
    $user = cableUser('showmax');
    Sanctum::actingAs($user);
    cableVendorFor('VTU.ng', 'vtung');

    $response = $this->postJson('/api/customer/cable/verify', [
        'cable_network' => 'showmax', 'identifier' => 'ada@example.com',
    ])->assertOk();

    expect($response->json('data.verification_required'))->toBeFalse()
        ->and($response->json('data.verified'))->toBeTrue();
    Http::assertNothingSent();
});

test('a Showmax purchase is built without requiring a decoder verification', function () {
    $user = cableUser('showmaxbuyer');
    $this->actingAs($user);
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    $plan = cablePlanWithFee([], 'showmax', 'Full');
    mapCablePlan($plan, $vendor, 8100, ['external_plan_id' => '2801', 'server_id' => '2801']);

    $payload = (new VTUNg($vendor))->formatPayload('cable', [
        'cable_plan' => $plan->id,
        'iuc' => 'ada@example.com',
        'subscription_type' => 'change',
        'tx_ref' => 'CABLE-SHOWMAX-1',
    ]);

    expect($payload['service_id'])->toBe('showmax')
        ->and($payload['variation_id'])->toBe('2801')
        ->and($payload)->not->toHaveKey('amount');
});

test('Showmax cannot be renewed', function () {
    $user = cableUser('showmaxrenew');
    Sanctum::actingAs($user);
    $vendor = cableVendorFor('VTU.ng', 'vtung');
    $plan = cablePlanWithFee([], 'showmax', 'Full');
    mapCablePlan($plan, $vendor, 8100);

    $this->postJson('/api/customer/cable/quote', [
        'cable_plan' => $plan->id, 'subscription_type' => 'renew', 'iuc' => 'ada@example.com',
    ])->assertStatus(422);
});
