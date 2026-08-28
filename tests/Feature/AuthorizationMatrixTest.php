<?php

use App\Http\Middleware\EnforceSecureSession;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([EnforceSecureSession::class, RequireRecentAuthentication::class]);
});

function matrixPermission(string $slug): Permission
{
    return Permission::firstOrCreate(['slug' => $slug], ['name' => ucwords(str_replace('_', ' ', $slug))]);
}

function matrixRole(string $slug, bool $staff, array $permissions = []): Role
{
    $role = Role::create(['name' => $slug === 'admin' ? 'Admin' : $slug, 'slug' => $slug, 'is_staff' => $staff, 'is_active' => true]);
    $role->permissions()->sync(collect($permissions)->map(fn ($slug) => matrixPermission($slug)->id));
    return $role;
}

function matrixUser(string $suffix, Role $role): User
{
    return User::create([
        'username' => "matrix_{$suffix}", 'fullname' => "Matrix {$suffix}",
        'email' => "matrix_{$suffix}@example.test", 'phone' => '070' . str_pad((string) abs(crc32("matrix_{$suffix}")), 8, '0', STR_PAD_LEFT),
        'password' => 'password', 'status' => 'active', 'role_id' => $role->id,
        // Deliberately contradictory for staff: authorization must ignore it.
        'user_type' => $role->is_staff ? 'user' : 'admin',
    ]);
}

function assertCanImpersonate($test, User $actor, User $customer): void
{
    Sanctum::actingAs($actor);
    $test->withSession([])->postJson("/api/admin/users/{$customer->id}/impersonate")->assertOk()
        ->assertJsonPath('success', true);
}

it('allows only owner and co-owner to impersonate when switch_account is assigned', function () {
    $customer = matrixUser('customer', matrixRole('basic', false));

    foreach (['owner', 'co-owner'] as $slug) {
        $actor = matrixUser($slug, matrixRole($slug, true, ['customers', 'switch_account']));
        assertCanImpersonate($this, $actor, $customer);
        // Reset the test client session before the next actor.
        $this->flushSession();
    }
});

it('denies non-owner staff even when switch_account is assigned', function () {
    $customer = matrixUser('restricted-customer', matrixRole('restricted-basic', false));

    foreach (['admin', 'customer-care', 'custom-manager'] as $slug) {
        $actor = matrixUser($slug, matrixRole($slug, true, ['customers', 'switch_account']));
        Sanctum::actingAs($actor);
        $this->withSession([])->postJson("/api/admin/users/{$customer->id}/impersonate")->assertForbidden();
    }
});

it('denies every staff role name when switch_account is absent', function () {
    $customer = matrixUser('customer-denied', matrixRole('basic-denied', false));

    foreach (['admin-no-switch', 'customer-care-no-switch'] as $slug) {
        $actor = matrixUser($slug, matrixRole($slug, true, ['customers']));
        Sanctum::actingAs($actor);
        $this->withSession([])->postJson("/api/admin/users/{$customer->id}/impersonate")->assertForbidden();
    }
});

it('denies customers and unauthenticated callers from admin impersonation APIs', function () {
    $customerRole = matrixRole('basic-customer', false, ['customers', 'switch_account']);
    $customer = matrixUser('plain-customer', $customerRole);
    $target = matrixUser('target-customer', matrixRole('target-basic', false));

    $this->postJson("/api/admin/users/{$target->id}/impersonate")->assertUnauthorized();
    Sanctum::actingAs($customer);
    $this->withSession([])->postJson("/api/admin/users/{$target->id}/impersonate")->assertForbidden();
});

it('uses active role staff state rather than the legacy user_type string', function () {
    $customer = matrixUser('legacy-target', matrixRole('legacy-basic', false));
    $staff = matrixUser('staff-user-type-user', matrixRole('owner', true, ['customers', 'switch_account']));
    expect($staff->user_type)->toBe('user');
    assertCanImpersonate($this, $staff, $customer);

    $fakeAdmin = matrixUser('customer-user-type-admin', matrixRole('not-staff', false, ['customers', 'switch_account']));
    expect($fakeAdmin->user_type)->toBe('admin');
    Sanctum::actingAs($fakeAdmin);
    $this->withSession([])->postJson("/api/admin/users/{$customer->id}/impersonate")->assertForbidden();
});

it('applies permission changes immediately without a permission cache', function () {
    $customer = matrixUser('cache-target', matrixRole('cache-basic', false));
    $role = matrixRole('owner', true, ['customers', 'switch_account']);
    $staff = matrixUser('mutable-staff', $role);
    assertCanImpersonate($this, $staff, $customer);

    $this->flushSession();
    $role->permissions()->detach(matrixPermission('switch_account'));
    Sanctum::actingAs($staff->fresh());
    $this->withSession([])->postJson("/api/admin/users/{$customer->id}/impersonate")->assertForbidden();
});

it('prevents customer managers from granting themselves roles or managing role definitions', function () {
    $managerRole = matrixRole('limited-manager', true, ['customers']);
    $manager = matrixUser('limited-manager', $managerRole);
    $ownerRole = matrixRole('owner', true, ['customers', 'manage_roles', 'manage_system_roles']);
    Sanctum::actingAs($manager);

    $this->putJson("/api/admin/users/{$manager->id}", ['role_id' => $ownerRole->id])->assertForbidden();
    $this->postJson('/api/admin/roles', ['name' => 'Escalated', 'slug' => 'escalated', 'permission_ids' => []])->assertForbidden();
});

it('reserves protected permissions for system-role managers', function () {
    $ordinary = matrixRole('ordinary-admin', true, ['manage_roles']);
    $ordinaryAdmin = matrixUser('ordinary-admin', $ordinary);
    $targetRole = matrixRole('editable-role', true);
    $migrationPermission = matrixPermission('migrations');
    Sanctum::actingAs($ordinaryAdmin);
    $this->putJson("/api/admin/roles/{$targetRole->id}", ['permission_ids' => [$migrationPermission->id]])->assertForbidden();

    $ownerRole = matrixRole('owner', true, ['manage_roles', 'manage_system_roles']);
    $owner = matrixUser('system-owner', $ownerRole);
    Sanctum::actingAs($owner);
    $this->postJson('/api/admin/roles', [
        'name' => 'Operations', 'slug' => 'operations', 'permission_ids' => [$migrationPermission->id],
    ])->assertCreated();
});

it('allows an owner to switch into another staff account', function () {
    $actor = matrixUser('switch-actor', matrixRole('owner', true, ['customers', 'switch_account']));
    $otherStaff = matrixUser('other-staff', matrixRole('other-staff', true));
    assertCanImpersonate($this, $actor, $otherStaff);
});

it('keeps capability and impersonation restrictions on the routes themselves', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes());
    $impersonate = $routes->first(fn ($route) => $route->uri() === 'api/admin/users/{id}/impersonate');
    $walletTransfer = $routes->first(fn ($route) => $route->uri() === 'api/customer/wallet-transfer' && in_array('POST', $route->methods(), true));
    $accountPassword = $routes->first(fn ($route) => $route->uri() === 'api/account/password');

    expect($impersonate)->not->toBeNull()
        ->and($impersonate->gatherMiddleware())->toContain('staff')
        ->and($impersonate->gatherMiddleware())->toContain('permission:customers')
        ->and($impersonate->gatherMiddleware())->toContain('permission:switch_account')
        ->and($walletTransfer->gatherMiddleware())->toContain('not.impersonating')
        ->and($accountPassword->gatherMiddleware())->toContain('not.impersonating');
});
