<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One observed field change on a DOHA ticket (field, old → new), written by
 * the sync. created_at = the sync run that observed it.
 */
class DohaTicketChange extends Model
{
    protected $fillable = [
        'doha_ticket_id',
        'field',
        'old_value',
        'new_value',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(DohaTicket::class, 'doha_ticket_id');
    }
}
