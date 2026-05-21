<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentTool extends Model
{
    use \App\Traits\HasActiveScope;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'category',
        'executor_type',
        'parameters_schema',
        'http_config',
        'custom_class',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'parameters_schema' => 'array',
        'http_config' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function toToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters_schema,
            ],
        ];
    }
}
