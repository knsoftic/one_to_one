<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * App-wide settings changed from the admin panel (sign-ups, notice for everyone, and the
 * integration settings of AppConfigService).
 */
class AppSetting extends Model
{
    public const DEFAULTS = [
        'registration_open' => true,
        'notice' => null,
        // Numbers typed as "0300 1234567" get this country code.
        'default_country_code' => '+92',
        // Email at sign-up: optional, required or hidden.
        'signup_email' => 'optional',
    ];

    private const CACHE_KEY = 'app-settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key): mixed
    {
        $stored = self::stored();

        // A setting saved as empty stays empty (it does not fall back to the default).
        return array_key_exists($key, $stored) ? $stored[$key] : (self::DEFAULTS[$key] ?? null);
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
