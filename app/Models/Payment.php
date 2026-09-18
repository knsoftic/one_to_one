<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to pay for a plan or a coin pack (Y2), through a manual transfer, Stripe, PayPal or
 * Google Play. `paid` (money taken) and `fulfilled` (coins credited / plan activated) are separate
 * states so a delivery failure is visible and can be retried by an admin.
 */
class Payment extends Model
{
    public const PURPOSES = ['plan', 'coins'];

    public const GATEWAYS = ['manual' => 'Manual transfer', 'stripe' => 'Card (Stripe)', 'paypal' => 'PayPal', 'play' => 'Google Play'];

    public const STATUSES = ['pending', 'review', 'paid', 'fulfilled', 'failed', 'rejected', 'cancelled', 'refunded'];

    public const FINAL = ['fulfilled', 'failed', 'rejected', 'cancelled', 'refunded'];

    public const MANUAL_METHODS = ['jazzcash' => 'JazzCash', 'easypaisa' => 'EasyPaisa', 'bank' => 'Bank transfer'];

    protected $fillable = [
        'uuid', 'client_token', 'user_id', 'purpose', 'plan_id', 'coin_pack_id', 'subscription_id', 'gateway', 'status',
        'platform', 'amount_minor', 'currency', 'coins', 'gateway_ref', 'gateway_capture_ref', 'manual_method',
        'proof_path', 'proof_ref', 'proof_note', 'reviewed_by', 'reviewed_at', 'review_note', 'paid_at', 'fulfilled_at',
        'refunded_at', 'refund_ref', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'coins' => 'integer',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function coinPack(): BelongsTo
    {
        return $this->belongsTo(CoinPack::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function scopeFinal(Builder $query): Builder
    {
        return $query->whereIn('status', self::FINAL);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    /** Short description for lists and notifications: "500 coins" / "Pro (1 month)". */
    public function itemLabel(): string
    {
        if ($this->purpose === 'coins') {
            return number_format((int) $this->coins).' coins';
        }
        $plan = $this->plan;

        return $plan ? $plan->name.' ('.($plan->period === 'year' ? '1 year' : '1 month').')' : 'Plan';
    }
}
