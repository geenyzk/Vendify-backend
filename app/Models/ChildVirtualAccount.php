<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A virtual account the parent generated for an affiliate child's customer.
 * Maps an incoming funding webhook back to the child customer it belongs to.
 */
class ChildVirtualAccount extends Model
{
    protected $fillable = [
        'child_instance_id', 'external_customer_id', 'child_customer_id',
        'provider', 'account_number', 'bank_name', 'account_name',
        'reference', 'email', 'phone', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function childInstance(): BelongsTo
    {
        return $this->belongsTo(ChildInstance::class);
    }

    public function childCustomer(): BelongsTo
    {
        return $this->belongsTo(ChildCustomer::class);
    }

    /**
     * Find the virtual account an incoming funding callback belongs to.
     *
     * Matches ONLY on the destination account number — the account the money
     * was actually paid into, which belongs to exactly one holder. Email is
     * deliberately NOT used: a parent user and a child customer can share an
     * email, and this runs (PaymentBase::webhook) BEFORE the parent-user
     * lookup, so an email fallback would hijack an ordinary parent deposit into
     * the child credit outbox and never credit the parent's wallet. When the
     * provider can't supply the account number (returns null), this returns
     * null and the funding is treated as a normal parent deposit.
     *
     * @param array<string, mixed> $callback
     */
    public static function resolveFromCallback(array $callback, ?string $accountNumber = null): ?self
    {
        if (empty($accountNumber)) {
            return null;
        }

        return static::where('account_number', $accountNumber)->first();
    }
}
