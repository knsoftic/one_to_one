<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * App-wide switches changed from the admin panel (sign-ups open, notice for everyone).
 */
class AppSetting extends Model
{
    public const DEFAULTS = [
        'registration_open' => true,
        'notice' => null,
    ];

    private const CACHE_KEY = 'app-settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key): mixed
    {
        return self::stored()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            self::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private static function stored(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 300, fn () => Schema::hasTable('app_settings')
                ? self::query()->pluck('value', 'key')->map(fn ($value) => json_decode((string) $value, true))->all()
                : []);
        } catch (Throwable) {
            return [];
        }
    }
}
