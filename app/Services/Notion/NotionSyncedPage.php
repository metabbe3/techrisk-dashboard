<?php

declare(strict_types=1);

namespace App\Services\Notion;

use App\Models\Incident;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotionSyncedPage extends Model
{
    protected $fillable = ['page_id', 'incident_id'];

    /** Null when the synced Issue was deleted — the tombstone a full sync restores from. */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
