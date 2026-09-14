<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * K6 — Someone rung for, or taking part in, a group call.
 */
class CallRoomParticipant extends Model
{
    public const STATUS_RINGING = 'ringing';

    public const STATUS_JOINED = 'joined';

    public const STATUS_LEFT = 'left';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_MISSED = 'missed';

    /** Counted against the room's size. */
    public const TAKING_PLACE = [self::STATUS_RINGING, self::STATUS_JOINED];

    protected $fillable = [
        'call_room_id',
        'user_id',
        'call_id',
        'invited_by',
        'status',
        'client_id',
        'join_seq',
        'joined_at',
        'left_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'join_seq' => 'integer',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(CallRoom::class, 'call_room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /**
     * @param  Builder<CallRoomParticipant>  $query
     */
    public function scopeJoined(Builder $query): void
    {
        $query->where('status', self::STATUS_JOINED);
    }

    public function isJoined(): bool
    {
        return $this->status === self::STATUS_JOINED;
    }
}
