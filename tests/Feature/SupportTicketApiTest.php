<?php

use App\Http\Middleware\EnforceSecureSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnforceSecureSession::class);
});

function supportUser(string $suffix, ?Role $role = null): User
{
    return User::create([
        'username' => "user_{$suffix}", 'fullname' => "User {$suffix}",
        'email' => "{$suffix}@example.test", 'phone' => '080' . str_pad((string) abs(crc32($suffix)), 8, '0', STR_PAD_LEFT),
        'password' => 'password', 'status' => 'active', 'role_id' => $role?->id,
    ]);
}

function supportAdmin(): User
{
    $permission = Permission::create(['name' => 'Support', 'slug' => 'support']);
    $role = Role::create(['name' => 'Customer care', 'slug' => 'customer-care']);
    $role->forceFill(['is_staff' => true])->save();
    $role->permissions()->attach($permission);
    return supportUser('admin', $role);
}

function supportTransaction(User $user): Transaction
{
    return Transaction::create([
        'user_id' => $user->id, 'transaction_type' => 'airtime_recharge', 'provider' => 'test',
        'account_or_phone' => '08012345678', 'amount' => 1000, 'status' => 'success',
        'transaction_reference' => Transaction::generateTransactionId(), 'balance_before' => 2000,
        'balance_after' => 1000, 'service_fee' => 0,
    ]);
}

function createSupportTicket(User $user, array $overrides = []): SupportTicket
{
    return SupportTicket::create($overrides + [
        'user_id' => $user->id, 'category' => 'other', 'subject' => 'I need some help',
        'description' => 'This is a detailed support request.',
    ]);
}

it('creates a ticket with a server reference and initial conversation message', function () {
    $user = supportUser('create');
    $transaction = supportTransaction($user);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/support/tickets', [
        'transaction_id' => $transaction->id, 'category' => 'transaction', 'issue_type' => 'not_received',
        'subject' => 'Airtime not received', 'description' => 'My wallet was charged but airtime was not delivered.',
    ])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonCount(1, 'data.messages');

    expect($response->json('data.reference'))->toMatch('/^VEN-[A-Z0-9]{7}$/');
    $this->assertDatabaseHas('support_tickets', ['user_id' => $user->id, 'transaction_id' => $transaction->id]);
});

it('lists and shows only the authenticated customer tickets', function () {
    $owner = supportUser('owner');
    $other = supportUser('other');
    $own = createSupportTicket($owner);
    $foreign = createSupportTicket($other);
    Sanctum::actingAs($owner);

    $this->getJson('/api/support/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson("/api/support/tickets/{$foreign->id}")->assertNotFound();
});

it('rejects a transaction owned by another customer', function () {
    $user = supportUser('tx-owner');
    $other = supportUser('tx-other');
    $transaction = supportTransaction($other);
    Sanctum::actingAs($user);

    $this->postJson('/api/support/tickets', [
        'transaction_id' => $transaction->id, 'category' => 'transaction', 'subject' => 'Wrong transaction',
        'description' => 'This transaction does not belong to this customer.',
    ])->assertUnprocessable()->assertJsonPath('success', false);
});

it('allows customer replies and reopens awaiting customer work for review', function () {
    $user = supportUser('reply');
    $ticket = createSupportTicket($user, ['status' => 'awaiting_customer']);
    Sanctum::actingAs($user);

    $this->postJson("/api/support/tickets/{$ticket->id}/messages", ['message' => 'Here is the requested information.'])
        ->assertCreated()->assertJsonPath('data.sender.role', 'customer');
    expect($ticket->fresh()->status)->toBe('in_review');
});

it('supports admin detail replies notes status and priority without leaking notes to customers', function () {
    $customer = supportUser('customer');
    $admin = supportAdmin();
    $ticket = createSupportTicket($customer);
    Sanctum::actingAs($admin);

    $this->getJson("/api/admin/support/tickets/{$ticket->id}")->assertOk()->assertJsonPath('data.customer.id', $customer->id);
    $this->postJson("/api/admin/support/tickets/{$ticket->id}/messages", ['message' => 'We are investigating this now.'])->assertCreated();
    $this->postJson("/api/admin/support/tickets/{$ticket->id}/notes", ['note' => 'Internal provider incident reference.'])->assertCreated();
    $this->patchJson("/api/admin/support/tickets/{$ticket->id}/priority", ['priority' => 'urgent'])->assertOk();
    $this->patchJson("/api/admin/support/tickets/{$ticket->id}/status", ['status' => 'resolved'])->assertOk();

    expect($ticket->fresh()->resolved_at)->not->toBeNull();
    Sanctum::actingAs($customer);
    $customerView = $this->getJson("/api/support/tickets/{$ticket->id}")->assertOk();
    expect($customerView->json('data'))->not->toHaveKey('internal_notes');
    expect(json_encode($customerView->json()))->not->toContain('Internal provider incident reference.');
});

it('validates support assignment eligibility', function () {
    $customer = supportUser('assign-customer');
    $ineligible = supportUser('ineligible');
    $admin = supportAdmin();
    $ticket = createSupportTicket($customer);
    Sanctum::actingAs($admin);

    $this->patchJson("/api/admin/support/tickets/{$ticket->id}/assignment", ['assigned_to' => $ineligible->id])->assertUnprocessable();
    $this->patchJson("/api/admin/support/tickets/{$ticket->id}/assignment", ['assigned_to' => $admin->id])->assertOk();
    expect($ticket->fresh()->assigned_to)->toBe($admin->id);
});
