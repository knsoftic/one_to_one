<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the append-only coin ledger (Y2). Every row carries a unique idempotency key
 * derived from its cause, so a replayed webhook, a re-run command or a double tap can never move
 * coins twice.
 */
class CoinTransaction extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'purchase' => 'Bought coins',
        'plan_coins' => 'Plan coins',
        'referral' => 'Referral reward',
        'referral_welcome' => 'Welcome coins',
        'referral_void' => 'Referral reversed',
        'promotion_hold' => 'Promotion',
        'promotion_refund' => 'Promotion refund',
        'badge' => 'Verified badge',
        'payment_refund' => 'Payment refunded',
        'admin_adjust' => 'Adjustment',
    ];

    protected $fillable = [
        'user_id', 'type', 'amount', 'withdrawable_delta', 'balance_after', 'withdrawable_after',
        'reference_type', 'reference_id', 'idempotency_key', 'note', 'meta', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'withdrawable_delta' => 'integer',
            'balance_after' => 'integer',
            'withdrawable_after' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo('reference', 'reference_type', 'reference_id');
    }

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }

    public function label(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }
}
