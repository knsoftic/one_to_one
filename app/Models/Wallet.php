<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's coins (Y2). Written only by CoinService, under a row lock. `withdrawable` is the
 * part of `balance` earned from referrals (shown as "Earned"); the rest was bought or granted.
 */
class Wallet extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'balance', 'withdrawable', 'earned_total', 'purchased_total', 'spent_total', 'frozen', 'updated_at'];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'withdrawable' => 'integer',
            'earned_total' => 'integer',
            'purchased_total' => 'integer',
            'spent_total' => 'integer',
            'frozen' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Coins that were bought or granted (everything that is not withdrawable). */
    public function purchased(): int
    {
        return max(0, $this->balance - $this->withdrawable);
    }
}
