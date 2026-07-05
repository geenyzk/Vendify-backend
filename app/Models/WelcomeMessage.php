<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WelcomeMessage extends Model
{
    protected $fillable = [
        'title',
        'body',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function views(): HasMany
    {
        return $this->hasMany(WelcomeMessageView::class);
    }
}
