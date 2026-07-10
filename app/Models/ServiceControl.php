<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ServiceControl extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceControlFactory> */
    use HasFactory;

    protected $fillable = ["name", "category", "sub_category", "isActive", "isDevLock"];
    protected $casts = [
        "isActive" => "boolean",
        "isDevLock" => "boolean",
    ];


     
    /**
     * Determine if this service requires a PIN.
     */
    static function requiresPin(): bool
    {

        return self::where("name", 'pin')
        ->where('isActive', true)
        ->exists()?true:false;
    }
}
