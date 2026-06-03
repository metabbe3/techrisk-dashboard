<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarRoomAgentConfig extends Model
{
    use HasFactory;
    use HasUuids;
    use \App\Traits\HasActiveScope;

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
        'enabled_tools',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'skills' => 'array',
        'enable_web_search' => 'boolean',
        'enabled_tools' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public static function findByRole(string $role): ?self
    {
        return static::allCached()->get($role);
    }

    public static function allCached(): \Illuminate\Support\Collection
    {
        return cache()->remember('war_room:agent_configs:keyed', now()->addMinutes(5), function () {
            return static::all()->keyBy('role_key');
        });
    }

    public function skillRecords()
    {
        return $this->belongsToMany(Skill::class, 'agent_skill')
            ->withTimestamps()
            ->orderBy('sort_order');
    }

    public static function getActiveAgents()
    {
        return static::active()->ordered()->get();
    }
}
