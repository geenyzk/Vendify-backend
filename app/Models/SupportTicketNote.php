<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketNote extends Model
{
    protected $fillable = ['support_ticket_id', 'author_id', 'note'];
    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'support_ticket_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
}
