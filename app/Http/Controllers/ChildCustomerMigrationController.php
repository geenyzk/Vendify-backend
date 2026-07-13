<?php

namespace App\Http\Controllers;

use App\Classes\Payment\Payment;
use App\Classes\TransactionService;
use App\Models\ChildCustomer;
use App\Models\ChildDirective;
use App\Models\ChildInstance;
use App\Models\Role;
use App\Models\User;
use App\Notifications\MigratedAccountInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The "promote to real account" admin action that
 * child_customers.migrated_to_user_id was reserved for in Phase 1.
 *
 * One call does the whole hand-off: resolve or create the parent User,
 * stamp migrated_to_user_id, and queue a redirect_user directive so the
 * child tells this customer to move over next time it polls.
 *
 * Transferred here: the customer's child wallet balance is moved to the
 * parent account on migration. The balance at migration time is returned
 * so the admin can confirm how much was carried over.
 */
class ChildCustomerMigrationController extends Controller
{
    public function migrate(Request $request, string $instanceId, string $customerId): JsonResponse
    {
        $validated = $request->validate([
            'target_url' => 'nullable|url',
        ]);

        $instance = ChildInstance::find($instanceId);
        if (!$instance) {
            return $this->fail([], 'Affiliate not found', 404);
        }

        $customer = ChildCustomer::where('child_instance_id', $instance->id)->find($customerId);
        if (!$customer) {
            return $this->fail([], 'Customer not found on this affiliate', 404);
        }

        if ($customer->migrated_to_user_id) {
            return $this->fail(['user_id' => $customer->migrated_to_user_id], 'Customer is already migrated', 409);
        }

        return $this->success(
            $this->migrateCustomer($instance, $customer, $validated['target_url'] ?? null),
            'Parent account created'
        );
    }

    public function bulkMigrate(Request $request, string $instanceId): JsonResponse
    {
        $validated = $request->validate([
            'customer_ids' => 'required|array|min:1',
            'customer_ids.*' => 'integer',
            'target_url' => 'nullable|url',
        ]);

        $instance = ChildInstance::find($instanceId);
        if (!$instance) {
            return $this->fail([], 'Affiliate not found', 404);
        }

        $results = [];
        foreach ($validated['customer_ids'] as $customerId) {
            $customer = ChildCustomer::where('child_instance_id', $instance->id)->find($customerId);
            if (!$customer || $customer->migrated_to_user_id) {
                continue;
            }

            $results[] = $this->migrateCustomer($instance, $customer, $validated['target_url'] ?? null);
        }

        return $this->success($results, 'Bulk migration completed');
    }

    protected function migrateCustomer(ChildInstance $instance, ChildCustomer $customer, ?string $targetUrl = null): array
    {
        // Identity resolution: email first, then phone — both are unique
        // identity columns on users. Username is deliberately not matched
        // (cross-platform username collisions are routine coincidences);
        // a taken username is deduped with a suffix instead.
        $existing = null;
        if ($customer->email) {
            $existing = User::where('email', $customer->email)->first();
        }
        if (!$existing && $customer->phone) {
            $existing = User::where('phone', $customer->phone)->first();
        }
        $linkedExisting = (bool) $existing;

        // users.email and users.phone are both NOT NULL + unique, so a
        // brand-new account needs both synced from the child.
        if (!$existing && (!$customer->email || !$customer->phone)) {
            throw new \RuntimeException("Cannot create a parent account: this customer has no " . (!$customer->email ? 'email address' : 'phone number') . " synced from the child yet.");
        }

        $targetUrl ??= config('app.frontend_url');
        $walletBalanceAtMigration = (float) $customer->wallet_balance;

        [$user, $directive] = DB::transaction(function () use ($instance, $customer, $existing, $targetUrl, $walletBalanceAtMigration) {
            $user = $existing ?? User::create([
                'fullname' => $customer->username ?: Str::before($customer->email, '@'),
                'username' => $this->availableUsername($customer),
                'email' => $customer->email,
                'phone' => $customer->phone,
                // Random throwaway — the migrated customer claims the account
                // through the normal "forgot password" email flow.
                'password' => Hash::make(Str::random(40)),
                'status' => 'active',
                'role_id' => Role::where('is_default', true)->value('id') ?? Role::where('slug', 'basic')->orWhere('name', 'basic')->value('id'),
            ]);

            $customer->update(['migrated_to_user_id' => $user->id]);

            if ($walletBalanceAtMigration > 0) {
                TransactionService::fundUser(
                    $user,
                    $walletBalanceAtMigration,
                    'credit',
                    'Migrated affiliate wallet balance',
                    'manual_funding',
                    'admin'
                );
                $customer->update(['wallet_balance' => 0]);
            }

            $directive = ChildDirective::create([
                'child_instance_id' => $instance->id,
                'type' => 'redirect_user',
                'payload' => [
                    'external_id' => $customer->external_id,
                    'target_url' => $targetUrl,
                    'parent_username' => $user->username,
                ],
                'status' => 'pending',
            ]);

            return [$user, $directive];
        });

        $inviteSent = false;
        if (!$linkedExisting) {
            // Same posture as registration: a payment-provider outage must
            // not fail the migration — the virtual account can be generated
            // again later.
            try {
                Payment::generateAccount($user);
            } catch (\Throwable $e) {
                Log::warning('Post-migration virtual account generation failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Claim email: a real broker token so "set your password" is the
            // account-claim step. Only for brand-new accounts — a linked
            // customer already knows their credentials here. Non-fatal: the
            // customer can always use the normal forgot-password flow.
            try {
                $user->notify(new MigratedAccountInvite(
                    Password::createToken($user),
                    $instance->name,
                ));
                $inviteSent = true;
            } catch (\Throwable $e) {
                Log::warning('Post-migration claim email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'user' => $user->only(['id', 'username', 'email', 'phone']),
            'linked_existing' => $linkedExisting,
            'invite_sent' => $inviteSent,
            'directive_id' => $directive->id,
            'wallet_balance_at_migration' => $walletBalanceAtMigration,
        ];
    }

    protected function availableUsername(ChildCustomer $customer): string
    {
        $base = Str::slug($customer->username ?: Str::before($customer->email, '@'), '_');
        if ($base === '') {
            $base = 'user';
        }

        $candidate = $base;
        while (User::where('username', $candidate)->exists()) {
            $candidate = $base . '_' . Str::lower(Str::random(4));
        }

        return $candidate;
    }
}
