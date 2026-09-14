<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's settings for one chat (never visible to the other person).
 */
class ChatSetting extends Model
{
    public const MAX_PINNED = 3;

    /** "Always" muted is stored as a far-future end. */
    public const MUTE_ALWAYS_UNTIL = '2999-12-31 23:59:59';

    /** Mute choices like WhatsApp: 8 hours, 1 week, always. */
    public const MUTE_DURATIONS = ['8h', '1w', 'always'];

    protected $fillable = [
        'conversation_id',
        'user_id',
        'pinned_at',
        'muted_until',
        'archived_at',
        'marked_unread',
        'favorite_at',
        'cleared_message_id',
        'cleared_at',
        'deleted_at',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
            'muted_until' => 'datetime',
            'archived_at' => 'datetime',
            'marked_unread' => 'boolean',
            'favorite_at' => 'datetime',
            'cleared_message_id' => 'integer',
            'cleared_at' => 'datetime',
            'deleted_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public static function muteEnd(string $duration): Carbon
    {
        return match ($duration) {
            '8h' => now()->addHours(8),
            '1w' => now()->addWeek(),
            default => Carbon::parse(self::MUTE_ALWAYS_UNTIL),
        };
    }

    /**
     * What the owner's apps need to know.
     *
     * @return array{pinned: bool, muted: bool, muted_until: ?string, mute_always: bool, archived: bool, marked_unread: bool, favorite: bool, cleared_at: ?string, locked: bool}
     */
    public static function payload(?self $setting): array
    {
        $muted = (bool) $setting?->isMuted();

        return [
            'pinned' => $setting?->pinned_at !== null,
            'pinned_at' => $setting?->pinned_at?->toIso8601String(),
            'muted' => $muted,
            'muted_until' => $muted ? $setting->muted_until->toIso8601String() : null,
            'mute_always' => $muted && $setting->muted_until->year >= 2999,
            'archived' => $setting?->archived_at !== null,
            'marked_unread' => (bool) $setting?->marked_unread,
            'favorite' => $setting?->favorite_at !== null,
            'cleared_at' => $setting?->cleared_at?->toIso8601String(),
            'locked' => $setting?->locked_at !== null,
        ];
    }
}
