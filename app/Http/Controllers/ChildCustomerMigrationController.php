<?php

namespace App\Http\Controllers;

use App\Class\Payment\Payment;
use App\Models\ChildCustomer;
use App\Models\ChildDirective;
use App\Models\ChildInstance;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The "promote to real account" admin action that
 * child_customers.migrated_to_user_id was reserved for in Phase 1.
 *
 * One call does the whole hand-off: resolve or create the parent User,
 * stamp migrated_to_user_id, and queue a redirect_user directive so the
 * child tells this customer to move over next time it polls.
 *
 * Deliberately NOT moved here: the customer's child wallet balance. That
 * is real money sitting on the child's books — crediting it on the parent
 * automatically is Phase 3 (money-moving) territory. The balance at
 * migration time is returned so the admin can settle it manually via the
 * existing /admin/users/{id}/fund endpoint if that's the arrangement.
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
            $missing = !$customer->email ? 'an email address' : 'a phone number';
            return $this->fail([], "Cannot create a parent account: this customer has no {$missing} synced from the child yet.", 422);
        }

        $targetUrl = $validated['target_url'] ?? config('app.frontend_url');

        [$user, $directive] = DB::transaction(function () use ($instance, $customer, $existing, $targetUrl) {
            $user = $existing ?? User::create([
                'fullname' => $customer->username ?: Str::before($customer->email, '@'),
                'username' => $this->availableUsername($customer),
                'email' => $customer->email,
                'phone' => $customer->phone,
                // Random throwaway — the migrated customer claims the account
                // through the normal "forgot password" email flow.
                'password' => Hash::make(Str::random(40)),
                'status' => 'active',
                'role_id' => Role::where('slug', 'basic')->orWhere('name', 'basic')->value('id'),
            ]);

            $customer->update(['migrated_to_user_id' => $user->id]);

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
        }

        return $this->success([
            'user' => $user->only(['id', 'username', 'email', 'phone']),
            'linked_existing' => $linkedExisting,
            'directive_id' => $directive->id,
            'wallet_balance_at_migration' => (float) $customer->wallet_balance,
        ], $linkedExisting ? 'Linked to an existing parent account' : 'Parent account created');
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
