<?php

namespace App\Services\AiManager\Tools;

use App\Models\User;
use App\Services\AiManager\AiManagerException;

/**
 * Change a customer's account status (active / suspended / banned). Suspended
 * and banned accounts are also flagged inactive. Mutating: proposal-only, gated
 * by `customers`.
 */
class UpdateUserStatusTool extends AiTool
{
    private const STATUSES = [
        User::STATUS_ACTIVE,
        User::STATUS_SUSPENDED,
        User::STATUS_BANNED,
    ];

    public function name(): string
    {
        return 'update_user_status';
    }

    public function description(): string
    {
        return 'Set a customer account status: "active" (normal access), "suspended" (temporary block), or "banned" (permanent block). Suspending or banning also marks the account inactive. Look the user up first with search_users or get_user. Creates a pending action an admin must approve.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'customers';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer', 'description' => 'Numeric id of the customer.'],
                'status' => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'The new account status.'],
                'reason' => ['type' => 'string', 'description' => 'Optional note recorded on the approval card.'],
            ],
            'required' => ['user_id', 'status'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer',
            'status' => 'required|in:' . implode(',', self::STATUSES),
            'reason' => 'nullable|string|max:255',
        ];
    }

    public function summarize(array $arguments): string
    {
        return "Set user #{$arguments['user_id']} status to '{$arguments['status']}'";
    }

    public function handle(array $arguments, User $actor): array
    {
        $user = User::find($arguments['user_id']);
        if (!$user) {
            throw new AiManagerException('User not found.');
        }

        if ($user->user_type === 'admin') {
            throw new AiManagerException('Admin accounts cannot be suspended or banned from here.');
        }

        $previous = $user->status;
        $user->status = $arguments['status'];
        $user->is_active = $arguments['status'] === User::STATUS_ACTIVE;
        $user->save();

        return [
            'updated' => true,
            'user_id' => $user->id,
            'previous_status' => $previous,
            'new_status' => $user->status,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
