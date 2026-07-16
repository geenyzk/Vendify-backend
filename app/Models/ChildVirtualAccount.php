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
     * Prefers the account number (unambiguous); falls back to the email the
     * account was created with when the payload doesn't echo the number.
     *
     * @param array<string, mixed> $callback
     */
    public static function resolveFromCallback(array $callback): ?self
    {
        // account_number is the unambiguous key; the PaymentPoint callback is
        // extended to surface the credited virtual account so we can use it.
        if (!empty($callback['account_number'])) {
            $match = static::where('account_number', $callback['account_number'])->first();
            if ($match) {
                return $match;
            }
        }

        // Fallback: the email the account was created with. Only used when the
        // payload carries no account number, and it still can't collide with a
        // parent user because the webhook only reaches here after no parent
        // User owns the email (see PaymentBase::webhook).
        $email = $callback['user_email'] ?? null;

        return !empty($email) ? static::where('email', $email)->first() : null;
    }
}
