<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class ActionImprovement extends Model implements Auditable
{
    use \App\Models\Concerns\SerializesDatesInAppTimezone;
    use HasFactory;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'incident_id',
        'title',
        'detail',
        'due_date',
        'pic_email',
        'reminder',
        'reminder_frequency',
        'status',
    ];

    protected $casts = [
        'pic_email' => 'array',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
