<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildSyncEvent extends Model
{
    protected $fillable = [
        'child_instance_id',
        'event_id',
        'resource',
        'record_count',
    ];

    public function childInstance(): BelongsTo
    {
        return $this->belongsTo(ChildInstance::class);
    }
}
