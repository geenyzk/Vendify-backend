<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockVending extends Model
{
    //
    protected $hidden = ["id", "created_at", "updated_at"];

    // One row, one column per service category — each holds the NAME of the
    // vendor that fulfils that category (see Vendor::scopeProvider). Without an
    // explicit fillable list the Universal Table API's `fill()` on update
    // silently discards every value (default $guarded = ['*']), so the
    // service-routing admin form would appear to save while persisting
    // nothing. Column names mirror the migration verbatim, spaces included.
    protected $fillable = [
        'sns',
        'vtu',
        'sme',
        'gifting',
        'cooperate gifting',
        'dstv',
        'gotv',
        'startimes',
        'data pin',
        'recharge pin',
        'exam',
        'electricity',
        'bulksms',
    ];
}
