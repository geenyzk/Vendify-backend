<?php

namespace App\Models;

use Carbon\Carbon;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'username', 'fullname', 'email', 'phone', 'password', 'pin',
        'user_type', 'role_id', 'wallet_balance', 'is_active', 'is_verified', 'status',
        'referral_code', 'referred_by', 'last_login_at', 'email_verified_at',
        'referral_balance', 'total_referral_earnings',
    ];

    protected $appends  = ["transactions", "banks", "stats", "referrals"];
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'wallet_balance' => 'decimal:2',
        'referral_balance' => 'decimal:2',
        'total_referral_earnings' => 'decimal:2',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->referral_code)) {
                $user->referral_code = self::generateUniqueReferralCode();
            }
        });
    }

    public static function generateUniqueReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    // User status constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BANNED = 'banned';
    public const STATUS_SUSPENDED = 'suspended';


    public function getReferralsAttribute()
    {
        return User::whereReferredBy($this->id)->get();
    }

    /**
     * Queryable version of the same relationship as getReferralsAttribute()
     * (kept alongside it rather than replacing it — a relation method and an
     * accessor of the same name don't conflict: `$user->referrals` still
     * hits the accessor, `$user->referrals()` builds a query). Used to
     * compute referral stats without loading every referral eagerly.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    /**
     * Queryable counterpart to getTransactionsAttribute() — same
     * coexistence rule as referrals()/getReferralsAttribute() above.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'user_id');
    }

    /**
     * Whether a transaction PIN has been set — safe to expose even though
     * the hashed `pin` column itself stays hidden, so the frontend can force
     * new/existing users without one into the PIN-setup flow.
     */
    public function getHasPinAttribute(): bool
    {
        return !is_null($this->pin);
    }

    function getTransactionsAttribute(){
        return Transaction::where("user_id", $this->id)->get();
    }

    function getStatsAttribute(){

        $transaction = Transaction::where("user_id", $this->id);
        return [
            "daily_purchased_data" => $transaction
            ->whereTransactionType("data_subscription")
            ->whereStatus("success")
            ->whereMonth("created_at", now()->month)
            ->whereYear("created_at", now()->year)->sum("quantity") . "GB",
            "monthly_tx" => $transaction
            ->whereMonth("created_at", now()->month)
            ->whereYear("created_at", now()->year)
        ];
    }


    function getBanksAttribute($query){
        return Bank::where("user_id", $this->id)->get();
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    private const PRICING_TIER_ROLES = ['agent', 'api', 'bonanza'];

    /**
     * Column prefix used by DataPlan/CablePlan/AirtimePinPlan/DataPinPlan/
     * Discount/ExamPlan price & discount lookups. agent/api/bonanza roles
     * get their own pricing column; every other role (basic, owner,
     * co-owner, customer-care) falls back to the base `user_*` columns —
     * staff aren't a separate retail pricing tier.
     */
    public function pricingTier(): string
    {
        $roleName = $this->role?->name;

        return in_array($roleName, self::PRICING_TIER_ROLES, true) ? $roleName : 'user';
    }
}
