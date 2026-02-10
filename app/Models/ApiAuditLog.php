<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiAuditLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'trace_id',
        'request_id',
        'request_timestamp',
        'method',
        'endpoint',
        'query_params',
        'request_body',
        'user_id',
        'user_email',
        'ip_address',
        'user_agent',
        'response_timestamp',
        'response_status',
        'response_time_ms',
        'response_size_bytes',
        'response_data',
        'error_message',
        'environment',
        'app_version',
        'metadata',
    ];

    protected $casts = [
        'request_timestamp' => 'datetime',
        'response_timestamp' => 'datetime',
        'query_params' => 'array',
        'request_body' => 'array',
        'response_data' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Get the user that made the request
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
