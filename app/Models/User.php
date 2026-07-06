<?php

namespace App\Models;

use Carbon\Carbon;
use App\Models\Transaction;
use App\Traits\HasRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, Notifiable, SoftDeletes, HasRole;

    protected $fillable = [
        'username', 'fullname', 'email', 'phone', 'password', 'pin',
        'user_type', 'role_id', 'wallet_balance', 'is_active', 'is_verified', 'status',
        'referral_code', 'referred_by', 'last_login_at', 'email_verified_at',
    ];

    protected $appends  = ["transactions", "banks", "stats", "referrals", "joined_at", "badges", "has_pin"];
    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected $casts = [
        'wallet_balance' => 'decimal:2',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'last_login_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'deleted_at' => 'datetime',
        'password' => 'hashed',
        'pin' => 'hashed',
    ];

    // User status constants
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BANNED = 'banned';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Return true if the user is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getReferralsAttribute()
    {
        return User::whereReferredBy($this->id)->get();
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
     */
    public function getBadgesAttribute()
    {
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
