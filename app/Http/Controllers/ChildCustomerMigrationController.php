<?php

namespace App\Http\Controllers;

use App\Classes\Payment\Payment;
use App\Classes\TemplateParser;
use App\Classes\TransactionService;
use App\Mail\AdminNotificationMail;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Promotes affiliate customers into real parent-platform user accounts.
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

        $blocker = $this->migrationBlocker($customer);
        if ($blocker) {
            return $this->fail($blocker['errors'], $blocker['message'], $blocker['code']);
        }

        $result = $this->migrateCustomer($instance, $customer, $validated['target_url'] ?? null);

        return $this->success($result['data'], $result['message']);
    }

    public function bulkMigrate(Request $request, string $instanceId): JsonResponse
    {
        $validated = $request->validate([
            'customer_ids' => 'required|array|min:1|max:500',
            'customer_ids.*' => 'required|distinct',
            'target_url' => 'nullable|url',
        ]);

        $instance = ChildInstance::find($instanceId);
        if (!$instance) {
            return $this->fail([], 'Affiliate not found', 404);
        }

        $customerIds = array_values(array_unique(array_map('strval', $validated['customer_ids'])));
        $customers = ChildCustomer::where('child_instance_id', $instance->id)
            ->whereIn('id', $customerIds)
            ->get()
            ->keyBy(fn (ChildCustomer $customer) => (string) $customer->id);

        $results = [];
        $migrated = 0;

        foreach ($customerIds as $customerId) {
            $customer = $customers->get($customerId);

            if (!$customer) {
                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'message' => 'Customer not found on this affiliate',
                    'errors' => [],
                ];
                continue;
            }

            $blocker = $this->migrationBlocker($customer);
            if ($blocker) {
                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'message' => $blocker['message'],
                    'errors' => $blocker['errors'],
                ];
                continue;
            }

            try {
                $result = $this->migrateCustomer($instance, $customer, $validated['target_url'] ?? null);
                $results[] = [
                    'customer_id' => $customerId,
                    'success' => true,
                    'message' => $result['message'],
                    'data' => $result['data'],
                ];
                $migrated++;
            } catch (Throwable $e) {
                Log::warning('Bulk affiliate customer migration failed', [
                    'child_instance_id' => $instance->id,
                    'child_customer_id' => $customerId,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'message' => 'Could not migrate this customer. Please try again.',
                    'errors' => [],
                ];
            }
        }

        return $this->success($results, "Migrated {$migrated} of " . count($customerIds) . ' affiliate customers');
    }

    public function emailAndMigrate(Request $request, string $instanceId): JsonResponse
    {
        $validated = $request->validate([
            'customer_ids' => 'required|array|min:1|max:500',
            'customer_ids.*' => 'required|distinct',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'target_url' => 'nullable|url',
        ]);

        $instance = ChildInstance::find($instanceId);
        if (!$instance) {
            return $this->fail([], 'Affiliate not found', 404);
        }

        $customerIds = array_values(array_unique(array_map('strval', $validated['customer_ids'])));
        $customers = ChildCustomer::where('child_instance_id', $instance->id)
            ->whereIn('id', $customerIds)
            ->get()
            ->keyBy(fn (ChildCustomer $customer) => (string) $customer->id);

        $results = [];
        $processed = 0;

        foreach ($customerIds as $customerId) {
            $customer = $customers->get($customerId);

            if (!$customer) {
                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'email_sent' => false,
                    'message' => 'Customer not found on this affiliate',
                    'errors' => [],
                ];
                continue;
            }

            $blocker = $this->migrationBlocker($customer);
            if ($blocker) {
                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'email_sent' => false,
                    'message' => $blocker['message'],
                    'errors' => $blocker['errors'],
                ];
                continue;
            }

            try {
                $parser = TemplateParser::make()->with(['user' => $customer]);
                Mail::to($customer->email)->send(new AdminNotificationMail(
                    $parser->parse($validated['subject']),
                    $parser->parse($validated['body']),
                ));
            } catch (Throwable $e) {
                Log::warning('Affiliate email-and-migrate email failed', [
                    'child_instance_id' => $instance->id,
                    'child_customer_id' => $customerId,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'email_sent' => false,
                    'message' => 'Email could not be sent, so migration was skipped.',
                    'errors' => [],
                ];
                continue;
            }

            try {
                $result = $this->migrateCustomer($instance, $customer, $validated['target_url'] ?? null);
                $results[] = [
                    'customer_id' => $customerId,
                    'success' => true,
                    'email_sent' => true,
                    'message' => 'Email sent and customer migrated',
                    'data' => $result['data'],
                ];
                $processed++;
            } catch (Throwable $e) {
                Log::warning('Affiliate email-and-migrate migration failed', [
                    'child_instance_id' => $instance->id,
                    'child_customer_id' => $customerId,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'email_sent' => true,
                    'message' => 'Email was sent, but migration failed. Please review this customer.',
                    'errors' => [],
                ];
            }
        }

        return $this->success($results, "Emailed and migrated {$processed} of " . count($customerIds) . ' affiliate customers');
    }

    protected function migrationBlocker(ChildCustomer $customer): ?array
    {
        if ($customer->migrated_to_user_id) {
            return [
                'message' => 'Customer is already migrated',
                'code' => 409,
                'errors' => ['user_id' => $customer->migrated_to_user_id],
            ];
        }

        if (!$customer->email || !$customer->phone) {
            $missing = !$customer->email ? 'email address' : 'phone number';

            return [
                'message' => "Cannot create a parent account: this customer has no {$missing} synced from the child yet.",
                'code' => 422,
                'errors' => [],
            ];
        }

        return null;
    }

    protected function migrateCustomer(ChildInstance $instance, ChildCustomer $customer, ?string $targetUrlOverride = null): array
    {
        $existing = null;
        if ($customer->email) {
            $existing = User::where('email', $customer->email)->first();
        }
        if (!$existing && $customer->phone) {
            $existing = User::where('phone', $customer->phone)->first();
        }

        $linkedExisting = (bool) $existing;
        $targetUrl = $targetUrlOverride ?? config('app.frontend_url');
        $walletBalanceAtMigration = (float) $customer->wallet_balance;

        [$user, $directive] = DB::transaction(function () use ($instance, $customer, $existing, $targetUrl, $walletBalanceAtMigration) {
            $user = $existing ?? User::create([
                'fullname' => $customer->username ?: Str::before($customer->email, '@'),
                'username' => $this->availableUsername($customer),
                'email' => $customer->email,
                'phone' => $customer->phone,
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
            try {
                Payment::generateAccount($user);
            } catch (Throwable $e) {
                Log::warning('Post-migration virtual account generation failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $user->notify(new MigratedAccountInvite(
                    Password::createToken($user),
                    $instance->name,
                ));
                $inviteSent = true;
            } catch (Throwable $e) {
                Log::warning('Post-migration claim email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'message' => $linkedExisting ? 'Linked to an existing parent account' : 'Parent account created',
            'data' => [
                'user' => $user->only(['id', 'username', 'email', 'phone']),
                'linked_existing' => $linkedExisting,
                'invite_sent' => $inviteSent,
                'directive_id' => $directive->id,
                'wallet_balance_at_migration' => $walletBalanceAtMigration,
            ],
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
