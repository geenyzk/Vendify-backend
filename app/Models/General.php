<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class General extends Model
{
    //

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
        return url("/images/logo.jpg");
    }
}
