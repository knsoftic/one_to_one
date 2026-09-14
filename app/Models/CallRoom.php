<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * K6 — A group call. People are rung one by one (a `calls` row each) and
 * connect to everyone else who joined.
 */
class CallRoom extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected $fillable = ['host_id', 'type', 'status', 'max_participants', 'link_token', 'ended_at'];

    protected function casts(): array
    {
        return [
            'max_participants' => 'integer',
            'ended_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CallRoomParticipant::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /**
     * @param  Builder<CallRoom>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function participantFor(User|int $user): ?CallRoomParticipant
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $this->participants()->where('user_id', $id)->first();
    }
}
