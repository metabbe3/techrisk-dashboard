<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

class ApiSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::remember("api_setting.{$key}", 3600, function () use ($key, $default) {
                $setting = static::where('key', $key)->first();

                return $setting ? json_decode($setting->value, true) : $default;
            });
        } catch (QueryException $e) {
            return $default;
        }
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => json_encode($value)]
        );

        Cache::forget("api_setting.{$key}");
    }

    public static function tableExists(): bool
    {
        try {
            static::limit(1)->first();

            return true;
        } catch (QueryException $e) {
            return false;
        }
    }
}
