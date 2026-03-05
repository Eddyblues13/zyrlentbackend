<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ApiSetting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key (cached for 60 min).
     */
    public static function getValue(string $key, $default = null): ?string
    {
        return Cache::remember("api_setting_{$key}", 3600, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            return $setting?->value ?? $default;
        });
    }

    /**
     * Set a setting value (upsert + clear cache).
     */
    public static function setValue(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("api_setting_{$key}");
    }
}
