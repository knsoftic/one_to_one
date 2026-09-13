<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_VOICE = 'voice';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_IMAGE, self::TYPE_DOCUMENT, self::TYPE_VOICE];

    /** Call-history entry created by the server (never sent by users). */
    public const TYPE_CALL = 'call';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_SEEN = 'seen';

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'receiver_id',
        'message',
        'message_type',
        'attachment',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
        'attachment_meta',
        'reply_to_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attachment_meta' => 'array',
            'attachment_size' => 'integer',
            'is_edited' => 'boolean',
            'deleted_for_sender' => 'boolean',
            'deleted_for_receiver' => 'boolean',
            'deleted_for_everyone' => 'boolean',
            'edited_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }

    /* -----------------------------------------------------------------
     |  Relationships
     | -----------------------------------------------------------------
     */

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'reply_to_id');
    }

    /* -----------------------------------------------------------------
     |  Scopes
     | -----------------------------------------------------------------
     */

    /**
     * Messages the given user has not deleted "for me".
     */
    public function scopeVisibleTo(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->where(function (Builder $q) use ($id) {
            $q->where(fn (Builder $s) => $s->where('sender_id', $id)->where('deleted_for_sender', false))
                ->orWhere(fn (Builder $r) => $r->where('receiver_id', $id)->where('deleted_for_receiver', false));
        });
    }

    /**
     * Messages received by the user that they have not seen yet.
     */
    public function scopeUnreadFor(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->where('receiver_id', $id)
            ->whereNull('seen_at')
            ->where('deleted_for_receiver', false)
            ->where('deleted_for_everyone', false);
    }

    /* -----------------------------------------------------------------
     |  Helpers
     | -----------------------------------------------------------------
     */

    public function isSentBy(User|int $user): bool
    {
        return (int) $this->sender_id === (int) ($user instanceof User ? $user->getKey() : $user);
    }

    public function involves(User|int $user): bool
    {
        $id = (int) ($user instanceof User ? $user->getKey() : $user);

        return (int) $this->sender_id === $id || (int) $this->receiver_id === $id;
    }

    public function isDeletedFor(User|int $user): bool
    {
        return $this->isSentBy($user) ? $this->deleted_for_sender : $this->deleted_for_receiver;
    }

    public function hasAttachment(): bool
    {
        return $this->attachment !== null && ! $this->deleted_for_everyone;
    }

    public function status(): string
    {
        return match (true) {
            $this->seen_at !== null => self::STATUS_SEEN,
            $this->delivered_at !== null => self::STATUS_DELIVERED,
            default => self::STATUS_SENT,
        };
    }

    /**
     * Short human-readable preview used in the recent chats list and notifications.
     */
    public function preview(int $limit = 60): string
    {
        if ($this->deleted_for_everyone) {
            return 'This message was deleted';
        }

        return match ($this->message_type) {
            self::TYPE_IMAGE => '📷 '.($this->message ? str($this->message)->limit($limit) : 'Photo'),
            self::TYPE_DOCUMENT => '📄 '.($this->attachment_name ?: 'Document'),
            self::TYPE_VOICE => '🎤 Voice message',
            self::TYPE_CALL => $this->callPreview(),
            default => (string) str((string) $this->message)->squish()->limit($limit),
        };
    }

    /**
     * Call history text: as the person called sees it (used in their notifications),
     * or as the caller sees it with $outgoing.
     */
    public function callPreview(bool $outgoing = false): string
    {
        $meta = $this->attachment_meta ?? [];
        $video = ($meta['call_type'] ?? Call::TYPE_AUDIO) === Call::TYPE_VIDEO;
        $kind = $video ? 'video call' : 'voice call';
        $icon = $video ? '📹' : '📞';

        if ($outgoing) {
            return $icon.' '.ucfirst($kind);
        }

        return match ($meta['reason'] ?? null) {
            Call::REASON_MISSED, Call::REASON_CANCELLED, Call::REASON_BUSY => "{$icon} Missed {$kind}",
            Call::REASON_DECLINED => "{$icon} Declined {$kind}",
            default => $icon.' '.ucfirst($kind),
        };
    }
}
