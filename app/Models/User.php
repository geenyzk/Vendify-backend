<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\Carbon;
use App\Models\Transaction;
use App\Notifications\VendifyResetPasswordNotification;
use App\Notifications\VendifyVerifyEmailNotification;
use App\Traits\HasRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Services\Auth\SessionSecurityService;

class User extends Authenticatable implements MustVerifyEmail
{
    use Auditable;
    use HasApiTokens, Notifiable, SoftDeletes, HasRole;

    /**
     * Churn from ordinary customer activity: balances move on every
     * transaction and last_login_at on every sign-in. Admin wallet
     * funding is audited explicitly, with its amount, instead.
     */
    protected array $auditExclude = ['wallet_balance', 'referral_balance', 'total_referral_earnings', 'last_login_at'];

    protected $fillable = [
        'username', 'fullname', 'email', 'phone', 'password', 'pin',
        'user_type', 'role_id', 'wallet_balance', 'is_active', 'is_verified', 'status',
        'referral_code', 'referred_by', 'last_login_at', 'email_verified_at',
        'referral_balance', 'total_referral_earnings',
    ];

    protected $appends  = ["transactions", "banks", "stats", "referrals", "joined_at", "badges", "has_pin"];
    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected $casts = [
        'wallet_balance' => 'decimal:2',
        'referral_balance' => 'decimal:2',
        'total_referral_earnings' => 'decimal:2',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'last_login_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'deleted_at' => 'datetime',
        'password' => 'hashed',
        'pin' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->referral_code)) {
                $user->referral_code = self::generateUniqueReferralCode();
            }
        });

        static::updated(function (User $user) {
            $passwordChanged = $user->wasChanged('password');
            $accountBlocked = ($user->wasChanged('status') && $user->status !== self::STATUS_ACTIVE)
                || ($user->wasChanged('is_active') && $user->is_active === false);
            if (!$passwordChanged && !$accountBlocked) {
                return;
            }

            try {
                app(SessionSecurityService::class)->revokeAllForUser(
                    $user,
                    $passwordChanged ? 'password_changed' : 'account_suspended',
                );
            } catch (\Throwable) {
                // During an in-progress deployment the auth session tables may
                // not exist yet; the request-time status check remains the
                // final enforcement layer once migrations complete.
            }
        });
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VendifyVerifyEmailNotification());
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new VendifyResetPasswordNotification($token));
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

    /**
     * Return true if the user is active.
     */
    public function isActive(): bool
    {
        // Legacy rows and test fixtures created before status enforcement may
        // have no status value. Only explicit non-active states are blocked;
        // the normalization migration converts old ban/suspend spellings.
        return $this->status === null || $this->status === '' || $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Pricing tier for the legacy per-tier discount columns on ExamPlan,
     * AirtimePinPlan and DataPinPlan — their price accessor reads
     * "{tier}_discount" (user_discount, agent_discount, api_discount,
     * bonanza_discount). This method was called by those accessors but never
     * defined, so serialising any of those plans for a logged-in user threw
     * "Call to undefined method pricingTier()". It mirrors
     * UserController::userTypeForRole and matches the stored user_type values.
     */
    public function pricingTier(): string
    {
        return $this->user_type ?: 'user';
    }

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

    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
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

    /**
     * Cosmetic badges earned via Events (see EventService) — only rows for
     * events with a badge reward that have actually been earned at least
     * once ride along here, so the frontend never has to cross-reference
     * event_awards against events itself.
     *
     * badges is in $appends, so this runs on EVERY user serialization —
     * including the login response. A deployment whose DB hasn't run the
     * events migrations yet must degrade to "no badges", not break login
     * over a cosmetic feature (this happened: see 2026-07-08 prod logs).
     */
    public function getBadgesAttribute()
    {
        try {
            return EventAward::where('user_id', $this->id)
                ->where('times_earned', '>', 0)
                ->whereHas('event', fn ($q) => $q->whereIn('reward_type', ['badge', 'both']))
                ->with('event:id,name,badge_name,badge_icon')
                ->get()
                ->map(fn (EventAward $award) => [
                    'event_id' => $award->event_id,
                    'name' => $award->event->badge_name ?? $award->event->name,
                    'icon' => $award->event->badge_icon,
                    'times_earned' => $award->times_earned,
                    'last_earned_at' => $award->last_earned_at,
                ])
                ->values();
        } catch (\Illuminate\Database\QueryException) {
            return collect();
        }
    }


    function getTransactionsAttribute()
    {
        return Transaction::where("user_id", $this->id)->get();
    }

    function getStatsAttribute()
    {
        $base = Transaction::where("user_id", $this->id);

        $now = now();

        // Monthly aggregate counts
        $transaction_count = (clone $base)
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->count();

        $monthly_successful = (clone $base)
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->where('status', 'success')
            ->count();

        $monthly_failed = (clone $base)
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->where('status', 'fail')
            ->count();

        $monthly_pending = (clone $base)
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->where('status', 'pending')
            ->count();

        // Build 5-day transactions count chart (labels + data)
        $days = collect();
        for ($i = 4; $i >= 0; $i--) {
            $days->push(Carbon::today()->subDays($i)->format('Y-m-d'));
        }

        $txData = Transaction::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('count(*) as total')
        )
            ->where('user_id', $this->id)
            ->whereDate('created_at', '>=', $days->first())
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $labels = [];
        $values = [];
        foreach ($days as $day) {
            $labels[] = Carbon::parse($day)->format('D');
            $values[] = $txData[$day]->total ?? 0;
        }

        return [
            "daily_purchased_data" => (clone $base)
                ->whereTransactionType("data_subscription")
                ->whereStatus("success")
                ->whereMonth("created_at", $now->month)
                ->whereYear("created_at", $now->year)->sum("quantity") . "GB",
            // raw monthly transactions (full collection)
            "monthly_tx" => (clone $base)
                ->whereMonth("created_at", $now->month)
                ->whereYear("created_at", $now->year)->get(),
            // counts and breakdowns
            "transaction_count" => $transaction_count,
            "monthly_successful" => $monthly_successful,
            "monthly_failed" => $monthly_failed,
            "monthly_pending" => $monthly_pending,
            "transaction_status" => [
                'successful' => $monthly_successful,
                'failed' => $monthly_failed,
                'pending' => $monthly_pending,
            ],
            // compact 5-day chart for quick display
            "tx_chart" => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Transactions',
                        'data' => $values,
                    ]
                ]
            ],
            // keep legacy amount-based 30-day chart as extra info
            "tx_amount_30d" => (clone $base)
                 ->selectRaw('DATE(created_at) as date, SUM(amount) as total_amount')
                ->whereStatus("success")
                ->whereBetween('created_at', [now()->subDays(30)->startOfDay(), now()->endOfDay()])
                ->groupBy('date')
                ->orderBy('date')
                ->get()
        ];
    }


    function getBanksAttribute($query)
    {
        return Bank::where("user_id", $this->id)->get();
    }

    public function loginStamp(): void
    {
        $this->update([
            'last_login_at' => now(),
        ]);
    }


    public function getLastLoginAtHumanAttribute(): ?string
    {
        return $this->last_login_at
            ? Carbon::parse($this->last_login_at)->diffForHumans()
            : null;
    }


    function getJoinedAtAttribute(){
        return $this->created_at
            ? Carbon::parse($this->created_at)->diffForHumans()
            : null;
    }


}
