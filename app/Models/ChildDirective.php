<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildDirective extends Model
{
    protected $fillable = [
        'child_instance_id',
        'type',
        'payload',
        'status',
        'delivered_at',
        'executed_at',
        'result_note',
    ];

    protected $casts = [
        'payload' => 'array',
        'delivered_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function childInstance(): BelongsTo
    {
        return $this->belongsTo(ChildInstance::class);
    }
}
