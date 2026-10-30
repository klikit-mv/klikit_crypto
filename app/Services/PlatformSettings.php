<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-editable runtime settings (e.g. trading halt, limits) layered over config defaults.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings';

    public function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::pluck('value', 'key')->all());

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        PlatformSetting::updateOrCreate(['key' => $key], ['value' => is_bool($value) ? ($value ? '1' : '0') : $value]);
        Cache::forget(self::CACHE_KEY);
    }

    public function tradingHalted(): bool
    {
        return (bool) $this->get('trading.halted', false);
    }

    public function limit(string $name): string
    {
        return (string) $this->get("trading.limits.$name", config("trading.limits.$name"));
    }
}
