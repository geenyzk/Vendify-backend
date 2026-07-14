<?php

namespace App\Services\AiManager\Tools;

use App\Models\AirtimeToCashRequest;
use App\Models\User;

/**
 * The airtime-to-cash review queue, so the assistant can report pending
 * requests and reference an id before proposing review_airtime_to_cash.
 */
class ListAirtimeToCashTool extends AiTool
{
    private const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'list_airtime_to_cash';
    }

    public function description(): string
    {
        return 'List airtime-to-cash requests, newest first, optionally filtered by status (pending, approved, rejected). Shows the network, declared airtime amount, computed payout, sender phone, requester, and status. Use this before proposing to approve or reject a request.';
    }

    public function permission(): ?string
    {
        return 'airtime_to_cash';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected'], 'description' => 'Optional status filter. Omit for all.'],
                'limit' => ['type' => 'integer', 'description' => 'Max rows (1-50, default 20).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'status' => 'nullable|in:pending,approved,rejected',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
        ];
    }

    public function handle(array $arguments, User $actor): array
    {
        $query = AirtimeToCashRequest::with('user:id,username,email,phone');

        if (!empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        $total = (clone $query)->count();
        $pending = (clone $query)->where('status', 'pending')->count();
        $limit = min((int) ($arguments['limit'] ?? 20), self::MAX_LIMIT);

        $rows = $query->latest()->limit($limit)->get()->map(fn (AirtimeToCashRequest $r) => [
            'id' => $r->id,
            'status' => $r->status,
            'network' => $r->network,
            'amount' => (float) $r->amount,
            'payout_amount' => (float) $r->payout_amount,
            'sender_phone' => $r->sender_phone,
            'proof_image' => $r->proof_image,
            'user' => $r->user ? ['id' => $r->user->id, 'username' => $r->user->username, 'email' => $r->user->email] : null,
            'created_at' => $r->created_at?->toDateTimeString(),
        ]);

        return [
            'total_matches' => $total,
            'pending_count' => $pending,
            'returned' => $rows->count(),
            'requests' => $rows,
        ];
    }
}
