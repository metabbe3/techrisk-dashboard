<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class AiUsageLog extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'user_id',
        'user_email',
        'field_type',
        'model',
        'input_length',
        'output_length',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'response_time_ms',
        'success',
        'error_message',
        'api_request_id',
        'metadata',
        'requested_at',
    ];

    protected $casts = [
        'success' => 'boolean',
        'metadata' => 'array',
        'requested_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
