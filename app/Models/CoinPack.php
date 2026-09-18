<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bundle of coins for sale (Y2): coins (+ bonus) for a price. The Google Play product id is
 * what the Android app buys through Play Billing.
 */
class CoinPack extends Model
{
    protected $fillable = [
        'name', 'coins', 'bonus_coins', 'price_minor', 'currency', 'price_usd_minor', 'play_product_id', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'coins' => 'integer',
            'bonus_coins' => 'integer',
            'price_minor' => 'integer',
            'price_usd_minor' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Coins the buyer receives. */
    public function totalCoins(): int
    {
        return (int) $this->coins + (int) $this->bonus_coins;
    }
}
