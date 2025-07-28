<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AirtimePinPlan extends Model
{
    //
    protected $appends = ["status", "price"];


    public function getStatusAttribute(){
        return $this->active ? "active": 'inactive';
    }

    public function getPriceAttribute(){
        $user = Auth::user();
        return $this->{$user->user_type?? "user" . "_price"};
    }
}
