<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A paid plan the admin created (Y2): a period, a price, and the benefits it includes. The
 * benefits are copied onto every subscription at purchase, so editing a plan never changes what a
 * running subscriber already has.
 */
class Plan extends Model
{
    public const PERIODS = ['month' => 'Monthly', 'year' => 'Yearly'];

    /** Limits a plan may raise; a missing key keeps the app default. */
    public const LIMIT_KEYS = ['upload_mb', 'group_members', 'broadcast_recipients', 'storage_mb'];

    protected $fillable = [
        'name', 'slug', 'description', 'period', 'price_minor', 'currency', 'price_usd_minor',
        'ads_off', 'verified_badge', 'monthly_coins', 'limits', 'play_product_id', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'price_usd_minor' => 'integer',
            'ads_off' => 'boolean',
            'verified_badge' => 'boolean',
            'monthly_coins' => 'integer',
            'limits' => 'array',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Months in one period. */
    public function months(): int
    {
        return $this->period === 'year' ? 12 : 1;
    }

    /** What a subscription copies at purchase. */
    public function benefits(): array
    {
        return [
            'ads_off' => (bool) $this->ads_off,
            'verified_badge' => (bool) $this->verified_badge,
            'monthly_coins' => (int) $this->monthly_coins,
            'limits' => array_filter(array_intersect_key($this->limits ?? [], array_flip(self::LIMIT_KEYS)), fn ($v) => $v !== null && $v !== ''),
        ];
    }
}
