<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use OwenIt\Auditing\Contracts\Auditable;

class Category extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    public const TYPE_BUSINESS_CATEGORY = 'business_category';
    public const TYPE_ROOT_CAUSE_CATEGORY = 'root_cause_category';
    public const TYPE_RESPONSIBLE_TEAM = 'responsible_team';

    protected $fillable = ['type', 'name'];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('categories:'.static::TYPE_BUSINESS_CATEGORY)
            && Cache::forget('categories:'.static::TYPE_ROOT_CAUSE_CATEGORY)
            && Cache::forget('categories:'.static::TYPE_RESPONSIBLE_TEAM));

        static::deleted(fn () => Cache::forget('categories:'.static::TYPE_BUSINESS_CATEGORY)
            && Cache::forget('categories:'.static::TYPE_ROOT_CAUSE_CATEGORY)
            && Cache::forget('categories:'.static::TYPE_RESPONSIBLE_TEAM));
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public static function options(string $type): array
    {
        return Cache::remember("categories:{$type}", now()->addHour(), fn () => static::where('type', $type)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->toArray());
    }
}
