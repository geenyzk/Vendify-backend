<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A problem the AI monitor spotted on its own (see AiMonitor middleware).
 * Unacknowledged alerts drive the floating AI button and topbar badge in the
 * admin UI; the monitor auto-acknowledges alerts whose condition has cleared.
 */
class AiAlert extends Model
{
    protected $fillable = [
        'severity',
        'title',
        'fingerprint',
        'acknowledged_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function scopeUnacknowledged($query)
    {
        return $query->whereNull('acknowledged_at');
    }
}
