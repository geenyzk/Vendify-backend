<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WelcomeMessageView extends Model
{
    protected $fillable = [
        'user_id',
        'welcome_message_id',
        'seen_at',
    ];

    protected $casts = [
        'seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function welcomeMessage(): BelongsTo
    {
        return $this->belongsTo(WelcomeMessage::class);
    }
}
