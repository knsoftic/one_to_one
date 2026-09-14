<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A one-to-one voice or video call.
 */
class Call extends Model
{
    public const TYPE_AUDIO = 'audio';

    public const TYPE_VIDEO = 'video';

    public const TYPES = [self::TYPE_AUDIO, self::TYPE_VIDEO];

    public const STATUS_RINGING = 'ringing';

    public const STATUS_ONGOING = 'ongoing';

    public const STATUS_ENDED = 'ended';

    public const ACTIVE_STATUSES = [self::STATUS_RINGING, self::STATUS_ONGOING];

    /** Answered and hung up normally. */
    public const REASON_COMPLETED = 'completed';

    /** The callee rejected it. */
    public const REASON_DECLINED = 'declined';

    /** Nobody answered before the ring timeout. */
    public const REASON_MISSED = 'missed';

    /** The caller hung up before it was answered. */
    public const REASON_CANCELLED = 'cancelled';

    /** The callee was already in another call. */
    public const REASON_BUSY = 'busy';

    /** The connection could not be established or was lost. */
    public const REASON_FAILED = 'failed';

    /** Reasons shown to the callee as a missed call. */
    public const MISSED_REASONS = [self::REASON_MISSED, self::REASON_CANCELLED, self::REASON_BUSY];

    protected $fillable = [
        'conversation_id',
        'call_room_id',
        'caller_id',
        'callee_id',
        'type',
        'status',
        'end_reason',
        'caller_client',
        'callee_client',
        'ringing_at',
        'answered_at',
        'ended_at',
        'ended_by',
        'duration',
        'caller_seen_at',
        'callee_seen_at',
        'message_id',
    ];

    protected function casts(): array
    {
        return [
            'ringing_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'caller_seen_at' => 'datetime',
            'callee_seen_at' => 'datetime',
            'duration' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** The group call this call rang someone into (K6). */
    public function room(): BelongsTo
    {
        return $this->belongsTo(CallRoom::class, 'call_room_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(CallSignal::class);
    }

    /**
     * @param  Builder<Call>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * @param  Builder<Call>  $query
     */
    public function scopeInvolving(Builder $query, User|int $user): void
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        $query->where(fn (Builder $q) => $q->where('caller_id', $id)->orWhere('callee_id', $id));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isRinging(): bool
    {
        return $this->status === self::STATUS_RINGING;
    }

    public function isOngoing(): bool
    {
        return $this->status === self::STATUS_ONGOING;
    }

    public function hasParticipant(User|int $user): bool
    {
        $id = (int) ($user instanceof User ? $user->getKey() : $user);

        return $id === (int) $this->caller_id || $id === (int) $this->callee_id;
    }

    public function isCaller(User|int $user): bool
    {
        return (int) ($user instanceof User ? $user->getKey() : $user) === (int) $this->caller_id;
    }

    public function otherParticipantId(User|int $user): int
    {
        return $this->isCaller($user) ? (int) $this->callee_id : (int) $this->caller_id;
    }

    /** The callee sees this call as missed. */
    public function isMissed(): bool
    {
        return $this->status === self::STATUS_ENDED && in_array($this->end_reason, self::MISSED_REASONS, true);
    }
}
