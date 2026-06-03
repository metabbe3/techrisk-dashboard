<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;

class WarRoomTemplate extends Model implements \OwenIt\Auditing\Contracts\Auditable
{
    use Auditable, HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'name',
        'selected_agents',
        'max_rounds',
        'model',
        'moderator_model',
        'enable_web_search',
        'deep_analysis',
        'user_instructions',
    ];

    protected $casts = [
        'selected_agents' => 'array',
        'enable_web_search' => 'boolean',
        'deep_analysis' => 'boolean',
        'max_rounds' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $template) {
            if (empty($template->id)) {
                $template->id = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
