<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChartConfiguration extends Model
{
    protected $fillable = [
        'name',
        'user_id',
        'metric',
        'dimension',
        'chart_type',
        'filters',
        'comparison',
    ];

    protected $casts = [
        'filters' => 'array',
        'comparison' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
