<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class General extends Model
{
    protected $fillable = [
        'app_name', 'app_phone', 'app_address', 'app_email',
        'bvn', 'bankName', 'accountName', 'accountNumber', 'logo',
        'meta_title', 'meta_description',
    ];

    protected $appends = ["app_type", "app_url", "app_logo"];

    function getAppTypeAttribute()
    {
        return env("APP_TYPE", "standalone");
    }

     public function getAppUrlAttribute()
    {
        return env("APP_URL", "http://localhost");
    }

    public function getAppLogoAttribute(){
        return url("/api/branding/logo");
    }
}
