<?php

namespace App\Http\Controllers;

use App\Classes\TemplateParser;
use App\HttpResponse;
use App\Mail\AdminNotificationMail;
use App\Models\Broadcast;
use App\Models\ChildCustomer;
use App\Models\ChildInstance;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BroadcastNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Full audience-targeting broadcast messaging: who (real roles, specific
 * individuals, or conditional criteria like "new users" / wallet balance
 * range / transaction volume range / referral count range), what
 * (per-channel content), how (email/sms/in-app), and when (now or
 * scheduled — see SendScheduledBroadcasts for the "when" half).
 */
class BroadcastController extends Controller
{
    use HttpResponse;

    private function filterRules(): array
    {
        return [
            'audience_mode' => 'required|in:criteria,individuals,child_customers',
            'child_instance_id' => 'required_if:audience_mode,child_customers|integer|exists:child_instances,id',
            'child_customer_ids' => 'nullable|array',
            'child_customer_ids.*' => 'integer|exists:child_customers,id',
            'user_ids' => 'required_if:audience_mode,individuals|array',
            'user_ids.*' => 'integer',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'integer|exists:roles,id',
            'signed_up_within_days' => 'nullable|integer|min:1',
            'wallet_balance_min' => 'nullable|numeric|min:0',
            'wallet_balance_max' => 'nullable|numeric|min:0',
            'transaction_count_min' => 'nullable|integer|min:0',
            'transaction_count_max' => 'nullable|integer|min:0',
            'transaction_amount_min' => 'nullable|numeric|min:0',
            'transaction_amount_max' => 'nullable|numeric|min:0',
            'referral_count_min' => 'nullable|integer|min:0',
            'referral_count_max' => 'nullable|integer|min:0',
        ];
    }

    private function contentRules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:Email,sms,database',
            'smsMessage' => 'nullable|string|max:160',
            'emailSubject' => 'nullable|string|max:255',
            'emailBody' => 'nullable|string',
            'notifTitle' => 'nullable|string|max:255',
            'notifMessage' => 'nullable|string',
            'sendNow' => 'required|boolean',
            'scheduleDate' => 'nullable|required_if:sendNow,false|date|after:now',
            'priorityHigh' => 'required|boolean',
        ];
    }

    /**
     * Builds (but doesn't execute) the audience query from filter criteria —
     * shared by the live count preview and the actual send, so "how many
     * will this reach" and "who actually gets it" can never drift apart.
     */
    private function resolveAudience(array $filters): Builder
    {
        if (($filters['audience_mode'] ?? 'criteria') === 'individuals') {
            return User::whereIn('id', $filters['user_ids'] ?? []);
        }

        $query = User::query();

        if (!empty($filters['role_ids'])) {
            $query->whereIn('role_id', $filters['role_ids']);
        }

        if (!empty($filters['signed_up_within_days'])) {
            $query->where('created_at', '>=', now()->subDays((int) $filters['signed_up_within_days']));
        }

        if (isset($filters['wallet_balance_min']) && $filters['wallet_balance_min'] !== null) {
            $query->where('wallet_balance', '>=', $filters['wallet_balance_min']);
        }
        if (isset($filters['wallet_balance_max']) && $filters['wallet_balance_max'] !== null) {
            $query->where('wallet_balance', '<=', $filters['wallet_balance_max']);
        }

        $hasReferralFilter = !empty($filters['referral_count_min'])
            || (isset($filters['referral_count_max']) && $filters['referral_count_max'] !== null);
        if ($hasReferralFilter) {
            $query->withCount('referrals');
            if (!empty($filters['referral_count_min'])) {
                $query->having('referrals_count', '>=', $filters['referral_count_min']);
            }
            if (isset($filters['referral_count_max']) && $filters['referral_count_max'] !== null) {
                $query->having('referrals_count', '<=', $filters['referral_count_max']);
            }
        }

        $txKeys = ['transaction_count_min', 'transaction_count_max', 'transaction_amount_min', 'transaction_amount_max'];
        $hasTxFilter = collect($txKeys)->contains(fn ($k) => isset($filters[$k]) && $filters[$k] !== null);
        if ($hasTxFilter) {
            $query->withCount(['transactions as successful_tx_count' => fn ($q) => $q->where('status', 'success')]);
            $query->withSum(['transactions as successful_tx_sum' => fn ($q) => $q->where('status', 'success')], 'amount');

            if (!empty($filters['transaction_count_min'])) {
                $query->having('successful_tx_count', '>=', $filters['transaction_count_min']);
            }
            if (isset($filters['transaction_count_max']) && $filters['transaction_count_max'] !== null) {
                $query->having('successful_tx_count', '<=', $filters['transaction_count_max']);
            }
            if (!empty($filters['transaction_amount_min'])) {
                $query->having('successful_tx_sum', '>=', $filters['transaction_amount_min']);
            }
            if (isset($filters['transaction_amount_max']) && $filters['transaction_amount_max'] !== null) {
                $query->having('successful_tx_sum', '<=', $filters['transaction_amount_max']);
            }
        }

        return $query;
    }

    private function deliveryAudience(array $validated): Builder
    {
        $query = $this->resolveAudience($validated);

        if (in_array('Email', $validated['channels'] ?? [], true)) {
            $query->whereNotNull('email')->where('email', '!=', '');
        }

        return $query;
    }

    /**
     * Child-affiliate customers with a synced email address — the only
     * channel we can reach them on (they have no login on the parent for
     * in-app, and SMS is reserved for our own users).
     */
    private function childCustomerAudience(array $filters): Builder
    {
        $query = ChildCustomer::where('child_instance_id', $filters['child_instance_id'])
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if (!empty($filters['child_customer_ids'])) {
            $query->whereIn('id', $filters['child_customer_ids']);
        }

        return $query;
    }

    private function describeAudience(array $filters): string
    {
        if (($filters['audience_mode'] ?? 'criteria') === 'child_customers') {
            $name = ChildInstance::find($filters['child_instance_id'] ?? null)?->name ?? 'affiliate';
            return "{$name} customers (email)";
        }

        if (($filters['audience_mode'] ?? 'criteria') === 'individuals') {
            $count = count($filters['user_ids'] ?? []);
            return "{$count} selected user" . ($count === 1 ? '' : 's');
        }

        $parts = [];
        if (!empty($filters['role_ids'])) {
            $names = Role::whereIn('id', $filters['role_ids'])->pluck('name')->all();
            $parts[] = 'role: ' . implode('/', $names);
        }
        if (!empty($filters['signed_up_within_days'])) {
            $parts[] = "signed up in last {$filters['signed_up_within_days']}d";
        }
        if (isset($filters['wallet_balance_min']) || isset($filters['wallet_balance_max'])) {
            $parts[] = 'wallet ₦' . ($filters['wallet_balance_min'] ?? 0) . '-' . ($filters['wallet_balance_max'] ?? '∞');
        }
        if (isset($filters['transaction_count_min']) || isset($filters['transaction_count_max'])) {
            $parts[] = 'tx count ' . ($filters['transaction_count_min'] ?? 0) . '-' . ($filters['transaction_count_max'] ?? '∞');
        }
        if (isset($filters['transaction_amount_min']) || isset($filters['transaction_amount_max'])) {
            $parts[] = 'tx volume ₦' . ($filters['transaction_amount_min'] ?? 0) . '-' . ($filters['transaction_amount_max'] ?? '∞');
        }
        if (isset($filters['referral_count_min']) || isset($filters['referral_count_max'])) {
            $parts[] = 'referrals ' . ($filters['referral_count_min'] ?? 0) . '-' . ($filters['referral_count_max'] ?? '∞');
        }

        return $parts === [] ? 'All users' : implode(' · ', $parts);
    }

    /**
     * Live "how many people will this reach" preview — recalculated as the
     * admin adjusts filters, before they commit to sending anything.
     */
    public function audienceCount(Request $request): JsonResponse
    {
        $validated = $request->validate($this->filterRules());

        $count = $validated['audience_mode'] === 'child_customers'
            ? $this->childCustomerAudience($validated)->count()
            : $this->resolveAudience($validated)->count();

        return $this->success(['count' => $count]);
    }

    /**
     * Lightweight lookup for the "select specific individuals" picker.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return $this->success([]);
        }

        $users = User::where(function ($query) use ($q) {
            $query->where('username', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('fullname', 'like', "%{$q}%");
        })->limit(20)->get(['id', 'username', 'fullname', 'email']);

        // User::$appends forces transactions/banks/stats/referrals/etc. to
        // serialize on every instance regardless of which columns were
        // selected above — map to a plain array so this stays the
        // lightweight autocomplete payload it's meant to be.
        $result = $users->map(fn (User $u) => [
            'id' => $u->id,
            'username' => $u->username,
            'fullname' => $u->fullname,
            'email' => $u->email,
        ]);

        return $this->success($result);
    }

    public function history(): JsonResponse
    {
        $broadcasts = Broadcast::latest()->limit(50)->get();

        return $this->success($broadcasts);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge($this->filterRules(), $this->contentRules()));

        // Child-affiliate customers aren't Users — they get their own
        // email-only send path instead of the Notifiable pipeline.
        if ($validated['audience_mode'] === 'child_customers') {
            return $this->sendToChildCustomers($validated);
        }

        $audience = $this->deliveryAudience($validated);
        $audienceLabel = $this->describeAudience($validated);
        $recipientCount = (clone $audience)->count();

        if ($recipientCount === 0) {
            return $this->fail(
                [],
                'No recipients matched this broadcast. Adjust the audience filters or choose a role with users before sending.',
                422,
            );
        }

        if ($validated['sendNow']) {
            $count = 0;
            $audience->chunkById(200, function ($users) use ($validated, &$count) {
                foreach ($users as $user) {
                    try {
                        $this->sendNotificationToUser($user, $validated);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::warning('Broadcast: failed to notify user', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                    }
                }
            });

            if ($count === 0) {
                return $this->fail(
                    [],
                    'The broadcast matched recipients, but no messages were delivered. Please check the mail configuration and try again.',
                    502,
                );
            }

            Broadcast::create([
                'name' => $validated['name'] ?? null,
                'title' => $validated['notifTitle'] ?? $validated['emailSubject'] ?? null,
                'message' => $validated['notifMessage'] ?? $validated['smsMessage'] ?? null,
                'channels' => $validated['channels'],
                'payload' => $validated,
                'audience_label' => $audienceLabel,
                'recipient_count' => $count,
                'scheduled_at' => null,
                'sent' => true,
            ]);

            return $this->success(['notified' => $count], "Notifications processed for {$count} users");
        }

        // Scheduled: persist only — SendScheduledBroadcasts picks this up
        // once scheduled_at is due (see routes/console.php).
        Broadcast::create([
            'name' => $validated['name'] ?? null,
            'title' => $validated['notifTitle'] ?? $validated['emailSubject'] ?? null,
            'message' => $validated['notifMessage'] ?? $validated['smsMessage'] ?? null,
            'channels' => $validated['channels'],
            'payload' => $validated,
            'audience_label' => $audienceLabel,
            'recipient_count' => $recipientCount,
            'scheduled_at' => $validated['scheduleDate'],
            'sent' => false,
        ]);

        return $this->success(
            ['notified' => 0, 'recipient_count' => $recipientCount],
            "Broadcast scheduled for {$recipientCount} recipients",
        );
    }

    /**
     * The child_customers half of send(): emails every synced customer of
     * one affiliate through the parent's own mail infra, with the same
     * {{ user.* }} placeholders the one-off contact modal supports —
     * resolved against the ChildCustomer row.
     */
    private function sendToChildCustomers(array $validated): JsonResponse
    {
        if (empty($validated['emailSubject']) || empty($validated['emailBody'])) {
            return $this->fail([], 'Email subject and body are required for affiliate customer broadcasts.', 422);
        }

        $audienceLabel = $this->describeAudience($validated);
        $recipientCount = $this->childCustomerAudience($validated)->count();

        if ($recipientCount === 0) {
            return $this->fail([], 'No affiliate customers with email addresses matched this broadcast.', 422);
        }

        if (!$validated['sendNow']) {
            Broadcast::create([
                'name' => $validated['name'] ?? null,
                'title' => $validated['emailSubject'],
                'message' => null,
                'channels' => ['Email'],
                'payload' => $validated,
                'audience_label' => $audienceLabel,
                'recipient_count' => $recipientCount,
                'scheduled_at' => $validated['scheduleDate'],
                'sent' => false,
            ]);

            return $this->success(
                ['notified' => 0, 'recipient_count' => $recipientCount],
                "Broadcast scheduled for {$recipientCount} affiliate customers",
            );
        }

        $count = $this->emailChildCustomers($validated);

        if ($count === 0) {
            return $this->fail(
                [],
                'The broadcast matched affiliate customers, but no emails were delivered. Please check the mail configuration and try again.',
                502,
            );
        }

        Broadcast::create([
            'name' => $validated['name'] ?? null,
            'title' => $validated['emailSubject'],
            'message' => null,
            'channels' => ['Email'],
            'payload' => $validated,
            'audience_label' => $audienceLabel,
            'recipient_count' => $count,
            'scheduled_at' => null,
            'sent' => true,
        ]);

        return $this->success(['notified' => $count], "Emails processed for {$count} affiliate customers");
    }

    private function emailChildCustomers(array $validated): int
    {
        $count = 0;
        $this->childCustomerAudience($validated)->chunkById(200, function ($customers) use ($validated, &$count) {
            foreach ($customers as $customer) {
                try {
                    $parser = TemplateParser::make()->with(['user' => $customer]);
                    Mail::to($customer->email)->send(new AdminNotificationMail(
                        $parser->parse($validated['emailSubject']),
                        $parser->parse($validated['emailBody']),
                    ));
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning('Broadcast: failed to email child customer', [
                        'child_customer_id' => $customer->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $count;
    }

    /**
     * Executes a previously-scheduled, not-yet-sent Broadcast row — called
     * by the broadcasts:send-scheduled command once scheduled_at is due.
     */
    public function executeScheduled(Broadcast $broadcast): void
    {
        $validated = $broadcast->payload ?? [];

        if (($validated['audience_mode'] ?? null) === 'child_customers') {
            $count = $this->emailChildCustomers($validated);
            if ($count === 0) {
                Log::warning('Scheduled broadcast: no affiliate customer emails were delivered', ['broadcast_id' => $broadcast->id]);
                return;
            }
            $broadcast->update(['sent' => true, 'recipient_count' => $count]);
            return;
        }

        $audience = $this->deliveryAudience($validated);

        $count = 0;
        $audience->chunkById(200, function ($users) use ($validated, &$count) {
            foreach ($users as $user) {
                try {
                    $this->sendNotificationToUser($user, $validated);
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning('Scheduled broadcast: failed to notify user', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        });

        if ($count === 0) {
            Log::warning('Scheduled broadcast: no user notifications were delivered', ['broadcast_id' => $broadcast->id]);
            return;
        }

        $broadcast->update(['sent' => true, 'recipient_count' => $count]);
    }

    private function sendNotificationToUser(User $user, array $validated): void
    {
        $parser = TemplateParser::make()->with(['user' => $user]);
        $parse = fn (?string $template) => $template === null ? null : $parser->parse($template);

        $data = array_merge($validated, [
            'emailSubject' => $parse($validated['emailSubject'] ?? null),
            'emailBody' => $parse($validated['emailBody'] ?? null),
            'notifTitle' => $parse($validated['notifTitle'] ?? null),
            'notifMessage' => $parse($validated['notifMessage'] ?? null),
            'smsMessage' => $parse($validated['smsMessage'] ?? null),
        ]);

        $user->notify(new BroadcastNotification($data));
    }
}
