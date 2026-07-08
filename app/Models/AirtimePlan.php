<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AirtimePlan extends Model
{
    protected $fillable = ["name", "category", "type", "min", "max", "active"];

    protected $casts = [
        "active" => "boolean",
    ];

    public function toArray()
    {
        $array = parent::toArray();
        $array['network'] = $array['name'];

        return $array;
    }
}
