<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAiPreference extends Model
{
    use \App\Traits\HasActiveScope;

    protected $fillable = [
        'user_id',
        'preference_rule',
        'source',
        'confidence',
        'is_active',
    ];

    protected $casts = [
        'confidence' => 'float',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
