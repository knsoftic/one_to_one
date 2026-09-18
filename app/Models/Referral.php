<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person invited by another (Y2). Created at sign-up as `pending`; rewarded exactly once when
 * the new person verifies their number; `void` (with a reason) when a check failed or an admin
 * reversed it.
 */
class Referral extends Model
{
    public const UPDATED_AT = null;

    public const STATUSES = ['pending', 'rewarded', 'void'];

    public const VOID_REASONS = [
        'self' => 'Self-referral',
        'referrer_inactive' => 'Referrer not active',
        'ip_cap' => 'Too many from one connection',
        'daily_cap' => 'Daily limit reached',
        'disabled' => 'Referrals were off',
        'deleted_early' => 'Account deleted soon after',
        'admin' => 'Reversed by an admin',
    ];

    protected $fillable = [
        'referrer_id', 'referred_id', 'code', 'status', 'void_reason', 'referrer_coins', 'referred_coins',
        'ip_hash', 'rewarded_at', 'voided_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'referrer_coins' => 'integer',
            'referred_coins' => 'integer',
            'rewarded_at' => 'datetime',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }
}
