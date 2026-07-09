<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI Manager chat thread owned by the admin who started it. See
 * App\Services\AiManager\AiManagerService for how turns are run.
 */
class AiConversation extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'last_activity_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AiActionProposal::class)->latest('id');
    }
}
