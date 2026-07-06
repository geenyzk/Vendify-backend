<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
<<<<<<< HEAD
    //
=======
    protected $fillable = [
        'referral_commission_rate',
        'invoice_prefix', 'invoice_suffix',
        'notify_admin_on_signup', 'notify_admin_on_funding',
        'notify_admin_on_large_transaction', 'large_transaction_threshold',
        'notify_admin_on_failed_transaction',
        'mail_mailer', 'mail_host', 'mail_port', 'mail_username',
        'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
        'registrations_open', 'signup_bonus_amount', 'min_wallet_funding_amount',
        'prune_transactions_enabled', 'prune_transactions_after_days', 'transactions_last_pruned_at',
        'wallet_transfer_min', 'wallet_transfer_max',
        'wallet_withdrawal_auto_approve', 'wallet_withdrawal_min', 'wallet_withdrawal_max',
        'notify_admin_on_airtime_to_cash', 'notify_admin_on_wallet_withdrawal',
    ];

    protected $casts = [
        'referral_commission_rate' => 'decimal:2',
        'large_transaction_threshold' => 'decimal:2',
        'signup_bonus_amount' => 'decimal:2',
        'min_wallet_funding_amount' => 'decimal:2',
        'notify_admin_on_signup' => 'boolean',
        'notify_admin_on_funding' => 'boolean',
        'notify_admin_on_large_transaction' => 'boolean',
        'notify_admin_on_failed_transaction' => 'boolean',
        'registrations_open' => 'boolean',
        'prune_transactions_enabled' => 'boolean',
        'prune_transactions_after_days' => 'integer',
        'transactions_last_pruned_at' => 'datetime',
        'wallet_transfer_min' => 'decimal:2',
        'wallet_transfer_max' => 'decimal:2',
        'wallet_withdrawal_auto_approve' => 'boolean',
        'wallet_withdrawal_min' => 'decimal:2',
        'wallet_withdrawal_max' => 'decimal:2',
        'notify_admin_on_airtime_to_cash' => 'boolean',
        'notify_admin_on_wallet_withdrawal' => 'boolean',
    ];

    // Mail password is sensitive but admin-only (mirrors how provider
    // api_key/secret_key are already exposed to admin elsewhere) — hide it
    // from any accidental non-admin serialization path, expose explicitly
    // only via the admin settings resource/service instead.
    protected $hidden = ['mail_password'];
>>>>>>> edbac78 (feat: Add in-app notifications for wallet transactions and airtime-to-cash requests, including admin alerts and user notifications)
}
