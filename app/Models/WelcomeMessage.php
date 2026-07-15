<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function views(): HasMany
    {
        return $this->hasMany(WelcomeMessageView::class);
    }
}
