<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;

class SupportTicketPresenter
{
    public static function transaction(?Transaction $transaction): ?array
    {
        if (!$transaction) return null;

        return [
            'id' => $transaction->id,
            'reference' => $transaction->transaction_reference,
            'type' => $transaction->transaction_type,
            'product' => $transaction->plan_type,
            'amount' => (string) $transaction->amount,
            'recipient' => $transaction->account_or_phone ?: $transaction->receiver,
            'status' => $transaction->status,
            'date' => $transaction->created_at,
        ];
    }

    public static function message($message): array
    {
        return [
            'id' => $message->id,
            'sender' => [
                'id' => $message->sender_id,
                'name' => $message->sender?->fullname ?: $message->sender?->username,
                'role' => $message->sender_role,
            ],
            'message' => $message->message,
            'created_at' => $message->created_at,
        ];
    }

    public static function base(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'reference' => $ticket->reference,
            'category' => $ticket->category,
            'issue_type' => $ticket->issue_type,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'assigned_to' => $ticket->assignee ? [
                'id' => $ticket->assignee->id,
                'name' => $ticket->assignee->fullname ?: $ticket->assignee->username,
            ] : null,
            'transaction' => self::transaction($ticket->transaction),
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
            'resolved_at' => $ticket->resolved_at,
            'closed_at' => $ticket->closed_at,
        ];
    }

    public static function customerDetail(SupportTicket $ticket): array
    {
        return self::base($ticket) + [
            'messages' => $ticket->messages->map(fn ($message) => self::message($message))->values(),
        ];
    }

    public static function customer(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->fullname ?: $user->username,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'is_active' => (bool) $user->is_active,
            'joined_at' => $user->created_at,
            'wallet_balance' => (string) $user->wallet_balance,
        ];
    }

    public static function adminDetail(SupportTicket $ticket): array
    {
        return self::customerDetail($ticket) + [
            'customer' => self::customer($ticket->user),
            'internal_notes' => $ticket->notes->map(fn ($note) => [
                'id' => $note->id,
                'note' => $note->note,
                'author' => [
                    'id' => $note->author_id,
                    'name' => $note->author?->fullname ?: $note->author?->username,
                ],
                'created_at' => $note->created_at,
            ])->values(),
        ];
    }
}
