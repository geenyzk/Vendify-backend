<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class WelcomeMessage extends Model
{
    use Auditable;
    protected $fillable = [
        'title',
        'body',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('customer:welcome-message:active'));
        static::deleted(fn () => Cache::forget('customer:welcome-message:active'));
    }

    public function views(): HasMany
    {
        return $this->hasMany(WelcomeMessageView::class);
    }
}
