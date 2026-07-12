<?php

namespace App\Services\AiManager\Tools;

use App\Models\Setting;
use App\Models\User;
use App\Services\AiManager\AiManagerException;

class UpdateSiteSettingsTool extends AiTool
{
    public function name(): string
    {
        return 'update_site_settings';
    }

    public function description(): string
    {
        return 'Propose updating the platform site settings such as referral commission, mail configuration, registration and transaction limits. Creates a pending action that must be approved before applying the changes.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function permission(): ?string
    {
        return 'settings';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'updates' => [
                    'type' => 'object',
                    'description' => 'Key/value pairs of Setting fields to update.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['updates'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'updates' => 'required|array',
            'updates.referral_commission_rate' => 'nullable|numeric|min:0|max:100',
            'updates.invoice_prefix' => 'nullable|string|max:50',
            'updates.invoice_suffix' => 'nullable|string|max:50',
            'updates.notify_admin_on_signup' => 'nullable|boolean',
            'updates.notify_admin_on_funding' => 'nullable|boolean',
            'updates.notify_admin_on_large_transaction' => 'nullable|boolean',
            'updates.large_transaction_threshold' => 'nullable|numeric|min:0',
            'updates.notify_admin_on_failed_transaction' => 'nullable|boolean',
            'updates.mail_mailer' => 'nullable|string|max:100',
            'updates.mail_host' => 'nullable|string|max:255',
            'updates.mail_port' => 'nullable|integer|min:1|max:65535',
            'updates.mail_username' => 'nullable|string|max:255',
            'updates.mail_password' => 'nullable|string|max:255',
            'updates.mail_encryption' => 'nullable|string|max:20',
            'updates.mail_from_address' => 'nullable|email|max:255',
            'updates.mail_from_name' => 'nullable|string|max:255',
            'updates.registrations_open' => 'nullable|boolean',
            'updates.signup_bonus_amount' => 'nullable|numeric|min:0',
            'updates.min_wallet_funding_amount' => 'nullable|numeric|min:0',
            'updates.prune_transactions_enabled' => 'nullable|boolean',
            'updates.prune_transactions_after_days' => 'nullable|integer|min:0',
            'updates.wallet_transfer_min' => 'nullable|numeric|min:0',
            'updates.wallet_transfer_max' => 'nullable|numeric|min:0',
            'updates.wallet_withdrawal_auto_approve' => 'nullable|boolean',
            'updates.wallet_withdrawal_min' => 'nullable|numeric|min:0',
            'updates.wallet_withdrawal_max' => 'nullable|numeric|min:0',
            'updates.notify_admin_on_airtime_to_cash' => 'nullable|boolean',
            'updates.notify_admin_on_wallet_withdrawal' => 'nullable|boolean',
        ];
    }

    public function summarize(array $arguments): string
    {
        $fields = array_keys($arguments['updates'] ?? []);
        return 'Update site settings: ' . implode(', ', $fields);
    }

    public function handle(array $arguments, User $actor): array
    {
        $updates = $arguments['updates'] ?? [];
        $setting = Setting::first();

        if (!$setting) {
            throw new AiManagerException('No settings record exists to update.');
        }

        $setting->fill($updates);
        $setting->save();

        return [
            'updated' => true,
            'settings' => $setting->toArray(),
        ];
    }
}
