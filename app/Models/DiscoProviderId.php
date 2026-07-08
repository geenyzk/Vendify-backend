<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscoProviderId extends Model
{
    protected $guarded = ['id'];

    /**
     * Look up the per-vendor ID mapping for a disco by name (e.g. "Ikeja
     * Electric"). Vendor classes read the column matching their own
     * provider row name off the result, e.g. $row->vtpass, $row->sme_plug,
     * $row->adex_server_1.
     */
    public static function forDisco(string $name): ?self
    {
        return self::where('name', $name)->first();
    }
}
