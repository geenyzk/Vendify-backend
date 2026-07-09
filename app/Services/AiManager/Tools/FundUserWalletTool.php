<?php

namespace App\Services\AiManager\Tools;

use App\Classes\TransactionService;
use App\Models\User;
use App\Services\AiManager\AiManagerException;

/**
 * Manually credit or debit a customer's wallet — mirrors
 * AdminController::fundUser (same TransactionService::fundUser primitive, which
 * records a manual_funding transaction and guards against overdrawing on a
 * debit). Mutating: proposal-only, re-checks the `wallets` permission.
 */
class FundUserWalletTool extends AiTool
{
    public function name(): string
    {
        return 'fund_user_wallet';
    }

    public function description(): string
    {
        return 'Propose crediting or debiting a customer\'s wallet by a fixed amount (a manual funding adjustment). A debit that exceeds the customer\'s balance will be rejected at execution. Creates a pending action an admin must approve; nothing moves until then.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'wallets';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer', 'description' => 'Numeric id of the customer.'],
                'amount' => ['type' => 'number', 'description' => 'Amount in NGN (must be > 0).'],
                'type' => ['type' => 'string', 'enum' => ['credit', 'debit'], 'description' => 'credit adds funds, debit removes them.'],
                'reason' => ['type' => 'string', 'description' => 'Why this adjustment is being made (audit trail).'],
            ],
            'required' => ['user_id', 'amount', 'type'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:credit,debit',
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function summarize(array $arguments): string
    {
        $reason = !empty($arguments['reason']) ? " — {$arguments['reason']}" : '';
        return ucfirst($arguments['type']) . " user #{$arguments['user_id']} wallet by NGN " . number_format((float) $arguments['amount'], 2) . $reason;
    }

    public function handle(array $arguments, User $actor): array
    {
        $user = User::find($arguments['user_id']);

        if (!$user) {
            throw new AiManagerException('Customer not found.');
        }

        $note = trim(($arguments['reason'] ?? 'Manual adjustment') . " (via AI Manager by {$actor->email})");

        try {
            TransactionService::fundUser($user, (float) $arguments['amount'], $arguments['type'], $note);
        } catch (\Throwable $e) {
            // e.g. "Insufficient balance for debit."
            throw new AiManagerException($e->getMessage());
        }

        return [
            'adjusted' => true,
            'user_id' => $user->id,
            'type' => $arguments['type'],
            'amount' => (float) $arguments['amount'],
            'new_balance' => (float) $user->fresh()->wallet_balance,
        ];
    }
}
