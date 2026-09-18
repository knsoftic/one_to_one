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
        // Ads (Y1): off until an admin turns them on; a house ad every N chats.
        'ads_enabled' => false,
        'ad_frequency' => 6,
        // Paid features (Y2): all off until an admin turns the master switch on.
        'paid_enabled' => false,
        'paid_currency' => 'PKR',
        'promote_enabled' => true,
        // Coins per 1,000 views, per kind of promotion.
        'promo_rate_status' => 100,
        'promo_rate_channel' => 100,
        'promo_rate_community' => 100,
        'promo_rate_business' => 120,
        'promo_rate_card' => 150,
        'promo_rate_link' => 200,
        'promo_min_coins' => 50,
        'promo_max_coins' => 50000,
        'promo_max_active' => 5,
        'promo_daily_cap' => 2,
        'promo_weight' => 2,
        'promo_auto_approve' => false,
        'promo_placements' => ['chat_list', 'status_list', 'channels'],
        'promo_blocked_hosts' => null,
        'referral_enabled' => true,
        'referral_reward' => 50,
        'referral_welcome' => 0,
        'referral_daily_cap' => 20,
        'referral_ip_cap' => 3,
        'badge_coin_price' => 500,
        'badge_days' => 365,
        'wallet_withdraw_enabled' => false,
        'manual_enabled' => true,
        'manual_jazzcash' => null,
        'manual_easypaisa' => null,
        'manual_bank' => null,
        'manual_note' => null,
        'manual_expire_hours' => 72,
        'proof_keep_days' => 90,
        'stripe_enabled' => false,
        'paypal_enabled' => false,
        'play_enabled' => false,
        'play_min_app_code' => null,
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
