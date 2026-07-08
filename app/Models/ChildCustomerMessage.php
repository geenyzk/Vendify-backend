<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildCustomerMessage extends Model
{
    protected $fillable = [
        'child_customer_id',
        'sent_by',
        'subject',
        'body',
    ];

    public function childCustomer(): BelongsTo
    {
        return $this->belongsTo(ChildCustomer::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
