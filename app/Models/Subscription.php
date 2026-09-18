<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One paid period of a plan for one person (Y2). Carries a snapshot of the plan's benefits, so
 * every entitlement check reads from here and never from the (editable) plan.
 */
class Subscription extends Model
{
    public const STATUSES = ['active', 'queued', 'expired', 'cancelled', 'revoked'];

    public const SOURCES = ['manual', 'stripe', 'paypal', 'play', 'admin'];

    protected $fillable = [
        'user_id', 'plan_id', 'payment_id', 'status', 'source', 'benefits', 'starts_at', 'ends_at',
        'coins_granted_periods', 'reminded_at', 'ended_by', 'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'benefits' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'coins_granted_periods' => 'integer',
            'reminded_at' => 'datetime',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('ends_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->ends_at?->isFuture();
    }

    public function benefit(string $key, mixed $default = null): mixed
    {
        return $this->benefits[$key] ?? $default;
    }
}
