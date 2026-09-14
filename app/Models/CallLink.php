<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * K7 — A link that lets people join a call with its owner.
 */
class CallLink extends Model
{
    public const MAX_ACTIVE_PER_USER = 10;

    protected $fillable = ['user_id', 'token', 'type', 'last_used_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<CallLink>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function url(): string
    {
        return route('call-links.show', $this->token);
    }
}
