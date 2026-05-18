<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'domain',
        'content',
        'frameworks',
        'tags',
        'difficulty',
        'is_active',
        'source',
        'source_id',
        'version',
        'sort_order',
    ];

    protected $casts = [
        'frameworks' => 'array',
        'tags' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('display_name');
    }

    public function scopeByDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function agents()
    {
        return $this->belongsToMany(WarRoomAgentConfig::class, 'agent_skill')
            ->withTimestamps();
    }
}
