<?php

use App\Http\Middleware\EnforceSecureSession;
use App\Models\General;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppSupportAgent;
use App\Models\WhatsAppSupportAssignment;
use App\Services\WhatsAppSupportRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnforceSecureSession::class);
    config(['support.whatsapp_sticky_minutes' => 60]);
});

function waRole(string $slug, bool $staff = false, array $permissions = []): Role
{
    $role = Role::create(['name' => "WA {$slug}", 'slug' => $slug, 'is_staff' => $staff, 'is_active' => true]);
    foreach ($permissions as $permission) {
        $model = Permission::firstOrCreate(['slug' => $permission], ['name' => $permission]);
        $role->permissions()->attach($model);
    }
    return $role;
}

function waUser(string $suffix, ?Role $role = null): User
{
    return User::create([
        'username' => "wa_{$suffix}", 'fullname' => "WA {$suffix}",
        'email' => "wa_{$suffix}@example.test", 'phone' => '081' . str_pad((string) abs(crc32("wa_{$suffix}")), 8, '0', STR_PAD_LEFT),
        'password' => 'password', 'status' => 'active', 'role_id' => $role?->id,
    ]);
}

function waAgent(string $name, string $phone, array $extra = []): WhatsAppSupportAgent
{
    return WhatsAppSupportAgent::create($extra + [
        'display_name' => $name, 'phone_number' => $phone,
        'enabled' => true, 'availability' => 'available',
    ]);
}

it('always selects the sole eligible agent', function () {
    $agent = waAgent('Only Agent', '+2348010000001');
    $first = app(WhatsAppSupportRoutingService::class)->route(waUser('one'));
    $second = app(WhatsAppSupportRoutingService::class)->route(waUser('two'));
    expect($first['phone'])->toBe('2348010000001')->and($second['phone'])->toBe('2348010000001');
    expect($agent->fresh()->assignment_count)->toBe(2);
});

it('rotates fairly across eligible agents and keeps counters consistent', function () {
    $a = waAgent('A', '+2348010000011', ['sort_order' => 1]);
    $b = waAgent('B', '+2348010000012', ['sort_order' => 2]);
    $router = app(WhatsAppSupportRoutingService::class);
    $phones = collect(range(1, 6))->map(fn ($i) => $router->route(waUser("rotation-{$i}"))['phone'])->all();

    expect($phones)->toBe(['2348010000011', '2348010000012', '2348010000011', '2348010000012', '2348010000011', '2348010000012']);
    expect($a->fresh()->assignment_count + $b->fresh()->assignment_count)->toBe(6)
        ->and(WhatsAppSupportAssignment::count())->toBe(6);
});

it('never routes to disabled unavailable or offline agents', function () {
    waAgent('Disabled', '+2348010000021', ['enabled' => false]);
    waAgent('Unavailable', '+2348010000022', ['availability' => 'unavailable']);
    waAgent('Offline', '+2348010000023', ['availability' => 'offline']);
    $available = waAgent('Available', '+2348010000024');
    $result = app(WhatsAppSupportRoutingService::class)->route(waUser('eligible'));
    expect($result['phone'])->toBe('2348010000024')->and($available->fresh()->assignment_count)->toBe(1);
});

it('uses customer stickiness and reroutes when the sticky agent becomes unavailable', function () {
    $a = waAgent('Sticky A', '+2348010000031');
    $b = waAgent('Sticky B', '+2348010000032');
    $customer = waUser('sticky');
    $router = app(WhatsAppSupportRoutingService::class);
    expect($router->route($customer)['phone'])->toBe('2348010000031')
        ->and($router->route($customer)['phone'])->toBe('2348010000031');
    $a->update(['availability' => 'offline']);
    expect($router->route($customer)['phone'])->toBe('2348010000032');
});

it('keeps a ticket on its assigned agent and validates ticket and transaction ownership', function () {
    waAgent('Ticket A', '+2348010000041');
    waAgent('Ticket B', '+2348010000042');
    $owner = waUser('ticket-owner');
    $other = waUser('ticket-other');
    $transaction = Transaction::create([
        'user_id' => $owner->id, 'transaction_type' => 'airtime_recharge', 'amount' => 500,
        'status' => 'success', 'transaction_reference' => Transaction::generateTransactionId(),
        'balance_before' => 1000, 'balance_after' => 500, 'service_fee' => 0,
    ]);
    $ticket = SupportTicket::create(['user_id' => $owner->id, 'transaction_id' => $transaction->id, 'category' => 'transaction', 'subject' => 'Missing airtime', 'description' => 'Airtime has not arrived yet.']);
    $router = app(WhatsAppSupportRoutingService::class);

    $first = $router->route($owner, $ticket->id);
    $second = $router->route($owner, $ticket->id);
    expect($second['phone'])->toBe($first['phone'])
        ->and($second['message'])->toContain($ticket->reference)->toContain($transaction->transaction_reference);
    expect(fn () => $router->route($other, $ticket->id))->toThrow(InvalidArgumentException::class);
    expect(fn () => $router->route($other, null, $transaction->id))->toThrow(InvalidArgumentException::class);
    Sanctum::actingAs($other);
    $this->postJson('/api/support/whatsapp/route', ['ticket_id' => $ticket->id])->assertUnprocessable();
    $this->postJson('/api/support/whatsapp/route', ['transaction_id' => $transaction->id])->assertUnprocessable();
});

it('uses the legacy app_phone fallback and fails intentionally without a valid fallback', function () {
    General::create(['app_phone' => '0801 000 0051']);
    $result = app(WhatsAppSupportRoutingService::class)->route(waUser('fallback'));
    expect($result['phone'])->toBe('2348010000051')->and($result['agent'])->toBe('Vendify Support');

    General::query()->update(['app_phone' => '#']);
    expect(fn () => app(WhatsAppSupportRoutingService::class)->route(waUser('no-fallback')))
        ->toThrow(RuntimeException::class);
});

it('normalizes numbers rejects duplicates and enforces admin permission', function () {
    $unauthorized = waUser('unauthorized-admin', waRole('wa-unauthorized', true));
    Sanctum::actingAs($unauthorized);
    $this->postJson('/api/admin/support/whatsapp-agents', ['display_name' => 'Agent', 'phone_number' => '08010000061'])->assertForbidden();

    $authorized = waUser('authorized-admin', waRole('wa-authorized', true, ['manage_whatsapp_support']));
    Sanctum::actingAs($authorized);
    $this->postJson('/api/admin/support/whatsapp-agents', ['display_name' => 'Agent', 'phone_number' => '0801 000 0061'])
        ->assertCreated()->assertJsonPath('data.phone_number', '+2348010000061');
    $this->postJson('/api/admin/support/whatsapp-agents', ['display_name' => 'Duplicate', 'phone_number' => '+2348010000061'])
        ->assertUnprocessable();
});
