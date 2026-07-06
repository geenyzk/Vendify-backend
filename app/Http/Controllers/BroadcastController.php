<?php

namespace App\Http\Controllers;

use App\Classes\TemplateParser;
use App\HttpResponse;
use App\Models\Broadcast;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BroadcastNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Full audience-targeting broadcast messaging: who (user types, real roles,
 * specific individuals, or conditional criteria like "new users" / wallet
 * balance range / transaction volume range / referral count range), what
 * (per-channel content), how (email/sms/in-app), and when (now or
 * scheduled — see SendScheduledBroadcasts for the "when" half).
 */
class BroadcastController extends Controller
{
    use HttpResponse;

    private function filterRules(): array
    {
        return [
            'audience_mode' => 'required|in:criteria,individuals',
            'user_ids' => 'required_if:audience_mode,individuals|array',
            'user_ids.*' => 'integer',
            'user_types' => 'nullable|array',
            'user_types.*' => 'in:user,agent,api,admin,bonanza',
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

        if (!empty($filters['user_types'])) {
            $query->whereIn('user_type', $filters['user_types']);
        }

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

    private function describeAudience(array $filters): string
    {
        if (($filters['audience_mode'] ?? 'criteria') === 'individuals') {
            $count = count($filters['user_ids'] ?? []);
            return "{$count} selected user" . ($count === 1 ? '' : 's');
        }

        $parts = [];
        if (!empty($filters['user_types'])) {
            $parts[] = implode('/', $filters['user_types']);
        }
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
        $count = $this->resolveAudience($validated)->count();

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

        return $this->success($users);
    }

    public function history(): JsonResponse
    {
        $broadcasts = Broadcast::latest()->limit(50)->get();

        return $this->success($broadcasts);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge($this->filterRules(), $this->contentRules()));

        $audience = $this->resolveAudience($validated);
        $audienceLabel = $this->describeAudience($validated);

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
        $recipientCount = (clone $audience)->count();

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
     * Executes a previously-scheduled, not-yet-sent Broadcast row — called
     * by the broadcasts:send-scheduled command once scheduled_at is due.
     */
    public function executeScheduled(Broadcast $broadcast): void
    {
        $validated = $broadcast->payload ?? [];
        $audience = $this->resolveAudience($validated);

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
