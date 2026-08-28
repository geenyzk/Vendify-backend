<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppSupportAgent extends Model
{
    use SoftDeletes;

    public const AVAILABILITIES = ['available', 'unavailable', 'offline'];

    protected $fillable = [
        'display_name', 'phone_number', 'enabled', 'availability', 'sort_order',
        'linked_user_id', 'created_by', 'department', 'notes',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_assigned_at' => 'datetime',
        'assignment_count' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function linkedUser(): BelongsTo { return $this->belongsTo(User::class, 'linked_user_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function assignments(): HasMany { return $this->hasMany(WhatsAppSupportAssignment::class, 'agent_id'); }
}
