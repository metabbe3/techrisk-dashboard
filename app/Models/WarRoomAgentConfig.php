<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WarRoomAgentConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'role_key',
        'display_name',
        'description',
        'skills',
        'icon',
        'color',
        'system_prompt',
        'model_override',
        'enable_web_search',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'skills' => 'array',
        'enable_web_search' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public static function findByRole(string $role): ?self
    {
        return static::where('role_key', $role)->first();
    }

    public static function getActiveAgents()
    {
        return static::active()->ordered()->get();
    }
}
