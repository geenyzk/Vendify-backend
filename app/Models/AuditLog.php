<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the append-only audit trail. Never updated after creation —
 * writes come only through AuditLogger; the API exposes reads only.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'actor_name', 'actor_email', 'action',
        'auditable_type', 'auditable_id', 'subject_label',
        'description', 'changes', 'context', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
        'context' => 'array',
    ];

    protected $appends = ['subject_type'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Short class name of the subject, e.g. "DataPlan" from the FQCN. */
    public function getSubjectTypeAttribute(): ?string
    {
        return $this->auditable_type ? class_basename($this->auditable_type) : null;
    }
}
