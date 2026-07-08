<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Broadcast extends Model
{
    use HasFactory;

    protected $table = 'broadcasts';

    protected $fillable = [
        'name',
        'title',
        'message',
        'channels',
        'payload',
        'audience_label',
        'recipient_count',
        'scheduled_at',
        'sent',
    ];

    protected $casts = [
        'channels' => 'array',
        'payload' => 'array',
        'sent' => 'boolean',
        'scheduled_at' => 'datetime',
    ];
}
