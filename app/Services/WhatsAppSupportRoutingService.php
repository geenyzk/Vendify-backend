<?php

namespace App\Services;

use App\Models\General;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppSupportAgent;
use App\Models\WhatsAppSupportAssignment;
use App\Support\WhatsAppPhoneNumber;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppSupportRoutingService
{
    public function route(User $customer, ?int $ticketId = null, ?int $transactionId = null): array
    {
        [$ticket, $transaction] = $this->resolveContext($customer, $ticketId, $transactionId);

        return DB::transaction(function () use ($customer, $ticket, $transaction) {
            // A customer-row lock makes simultaneous repeat clicks sticky: the
            // second request waits, then observes the first assignment.
            User::whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $agent = $this->stickyAgent($customer, $ticket);
            if (!$agent) {
                // Persisted least-recent assignment is round-robin across all
                // workers. The selected row lock prevents concurrent requests
                // from incrementing the same stale candidate unchecked.
                $agent = WhatsAppSupportAgent::query()
                    ->where('enabled', true)
                    ->where('availability', 'available')
                    ->orderBy('assignment_count')
                    ->orderByRaw('CASE WHEN last_assigned_at IS NULL THEN 0 ELSE 1 END')
                    ->orderBy('last_assigned_at')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
            }

            $source = $ticket ? 'ticket' : ($transaction ? 'transaction' : 'generic');
            if ($agent) {
                $phone = $agent->phone_number;
                $agent->increment('assignment_count', 1, ['last_assigned_at' => now()]);
            } else {
                $fallback = General::query()->value('app_phone');
                try {
                    $phone = $fallback ? WhatsAppPhoneNumber::normalize($fallback) : null;
                } catch (InvalidArgumentException) {
                    $phone = null;
                }
                if (!$phone) throw new RuntimeException('WhatsApp support is currently unavailable.');
                $source = 'fallback';
            }

            WhatsAppSupportAssignment::create([
                'agent_id' => $agent?->id,
                'customer_id' => $customer->id,
                'ticket_id' => $ticket?->id,
                'transaction_id' => $transaction?->id,
                'phone_number' => $phone,
                'source' => $source,
                'assigned_at' => now(),
            ]);

            $message = $this->message($ticket, $transaction);
            $target = WhatsAppPhoneNumber::target($phone);

            return [
                'agent' => $agent?->display_name ?: 'Vendify Support',
                'phone' => $target,
                'whatsapp_url' => 'https://wa.me/' . $target . '?text=' . rawurlencode($message),
                'message' => $message,
                'ticket_reference' => $ticket?->reference,
                'transaction_reference' => $transaction?->transaction_reference,
            ];
        }, 3);
    }

    private function stickyAgent(User $customer, ?SupportTicket $ticket): ?WhatsAppSupportAgent
    {
        $query = WhatsAppSupportAssignment::query()
            ->whereNotNull('agent_id')
            ->whereHas('agent', fn ($agent) => $agent->where('enabled', true)->where('availability', 'available'));

        if ($ticket) {
            $assignment = (clone $query)->where('ticket_id', $ticket->id)->latest('assigned_at')->first();
            if ($assignment) return WhatsAppSupportAgent::whereKey($assignment->agent_id)->where('enabled', true)->where('availability', 'available')->lockForUpdate()->first();
        }

        $minutes = max(1, (int) config('support.whatsapp_sticky_minutes', 60));
        $assignment = $query->where('customer_id', $customer->id)
            ->where('assigned_at', '>=', now()->subMinutes($minutes))
            ->latest('assigned_at')->first();

        return $assignment ? WhatsAppSupportAgent::whereKey($assignment->agent_id)->where('enabled', true)->where('availability', 'available')->lockForUpdate()->first() : null;
    }

    private function resolveContext(User $customer, ?int $ticketId, ?int $transactionId): array
    {
        $ticket = null;
        $transaction = null;
        if ($ticketId) {
            $ticket = SupportTicket::with('transaction')->whereKey($ticketId)->where('user_id', $customer->id)->first();
            if (!$ticket) throw new InvalidArgumentException('The selected support ticket is invalid.');
            $transaction = $ticket->transaction;
        }
        if ($transactionId) {
            $selected = Transaction::whereKey($transactionId)->where('user_id', $customer->id)->first();
            if (!$selected) throw new InvalidArgumentException('The selected transaction is invalid.');
            if ($transaction && $transaction->id !== $selected->id) {
                throw new InvalidArgumentException('The transaction does not belong to the selected ticket.');
            }
            $transaction = $selected;
        }
        return [$ticket, $transaction];
    }

    private function message(?SupportTicket $ticket, ?Transaction $transaction): string
    {
        if ($ticket && $transaction) return "Hi Vendify Support, I need help with ticket {$ticket->reference} regarding transaction {$transaction->transaction_reference}.";
        if ($ticket) return "Hi Vendify Support, I need help with ticket {$ticket->reference}.";
        if ($transaction) return "Hi Vendify Support, I need help with transaction {$transaction->transaction_reference}.";
        return 'Hi Vendify Support, I need help with my account.';
    }
}
