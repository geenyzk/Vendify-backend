<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BettingSetting extends Model
{
    protected $fillable = ['enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public static function current(): self
    {
        return self::firstOrCreate([], ['enabled' => false]);
    }
}
