<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Broadcast extends Model
{
    use HasFactory;

    protected $table = 'broadcasts';

    protected $fillable = [
        'title',
        'message',
        'channels',
        'audience_label',
        'scheduled_at',
        'sent',
    ];

    protected $casts = [
        'channels' => 'array',
        'sent' => 'boolean',
        'scheduled_at' => 'datetime',
    ];
}
