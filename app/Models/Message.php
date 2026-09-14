<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_VOICE = 'voice';

    public const TYPE_VIDEO = 'video';

    public const TYPE_STICKER = 'sticker';

    public const TYPE_LOCATION = 'location';

    public const TYPE_CONTACT = 'contact';

    public const TYPE_POLL = 'poll';

    /** Notice written by the app in a chat (e.g. disappearing messages turned on). */
    public const TYPE_SYSTEM = 'system';

    /** Live location sharing durations (minutes), like WhatsApp. */
    public const LIVE_LOCATION_MINUTES = [15, 60, 480];

    public const TYPES = [self::TYPE_TEXT, self::TYPE_IMAGE, self::TYPE_DOCUMENT, self::TYPE_VOICE, self::TYPE_VIDEO, self::TYPE_STICKER, self::TYPE_LOCATION, self::TYPE_CONTACT, self::TYPE_POLL];

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
        'broadcast_message_id',
        'forward_count',
        'link_preview_id',
        'sent_at',
        'expires_at',
    ];

    /** Relations shown with every message in lists and broadcasts. */
    public const DISPLAY_RELATIONS = ['replyTo', 'reactions', 'linkPreview', 'pollVotes'];

    protected function casts(): array
    {
        return [
            'attachment_meta' => 'array',
            'attachment_size' => 'integer',
            'forward_count' => 'integer',
            'is_edited' => 'boolean',
            'deleted_for_sender' => 'boolean',
            'deleted_for_receiver' => 'boolean',
            'deleted_for_everyone' => 'boolean',
            'edited_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'seen_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class)->orderBy('id');
    }

    public function stars(): HasMany
    {
        return $this->hasMany(StarredMessage::class);
    }

    public function pollVotes(): HasMany
    {
        return $this->hasMany(PollVote::class)->orderBy('id');
    }

    public function linkPreview(): BelongsTo
    {
        return $this->belongsTo(LinkPreview::class);
    }

    /* -----------------------------------------------------------------
     |  Per-viewer attributes
     | -----------------------------------------------------------------
     */

    /**
     * Adds `is_starred` for the given user.
     */
    public function scopeWithViewerState(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->withExists(['stars as is_starred' => fn (Builder $q) => $q->where('user_id', $id)]);
    }

    /* -----------------------------------------------------------------
     |  Scopes
     | -----------------------------------------------------------------
     */

    /**
     * Messages the given user has not deleted "for me" or cleared from the chat.
     */
    public function scopeVisibleTo(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->where(function (Builder $q) use ($id, $query) {
            $q->where(fn (Builder $s) => $s->where('sender_id', $id)->where('deleted_for_sender', false))
                ->orWhere(fn (Builder $r) => $r->where('receiver_id', $id)->where('deleted_for_receiver', false))
                // Group messages (no receiver): sent while the user was in the group, not deleted for them.
                ->orWhere(fn (Builder $g) => $g->whereNull($query->qualifyColumn('receiver_id'))
                    ->where($query->qualifyColumn('sender_id'), '!=', $id)
                    ->whereExists(fn ($sub) => $this->memberWindow($sub, $query, $id))
                    ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('message_hides')
                        ->whereColumn('message_hides.message_id', $query->qualifyColumn('id'))
                        ->where('message_hides.user_id', $id)));
        })->notClearedFor($id);
    }

    /**
     * Messages received by the user that they have not seen yet.
     * Notes to self never count as unread; in groups, neither do app notices.
     */
    public function scopeUnreadFor(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query
            ->where($query->qualifyColumn('sender_id'), '!=', $id)
            ->where($query->qualifyColumn('deleted_for_everyone'), false)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $d) => $d->where('receiver_id', $id)->whereNull('seen_at')->where('deleted_for_receiver', false))
                ->orWhere(fn (Builder $g) => $g->whereNull($query->qualifyColumn('receiver_id'))
                    ->where($query->qualifyColumn('message_type'), '!=', self::TYPE_SYSTEM)
                    ->whereExists(fn ($sub) => $this->memberWindow($sub, $query, $id)
                        ->whereColumn('conversation_members.last_read_message_id', '<', $query->qualifyColumn('id')))
                    ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('message_hides')
                        ->whereColumn('message_hides.message_id', $query->qualifyColumn('id'))
                        ->where('message_hides.user_id', $id))))
            ->notClearedFor($id);
    }

    /**
     * Subquery: the user's group membership covers the message.
     */
    private function memberWindow($sub, Builder $query, int $userId)
    {
        return $sub->selectRaw('1')
            ->from('conversation_members')
            ->whereColumn('conversation_members.conversation_id', $query->qualifyColumn('conversation_id'))
            ->where('conversation_members.user_id', $userId)
            ->whereColumn('conversation_members.visible_from_message_id', '<=', $query->qualifyColumn('id'))
            ->where(fn ($w) => $w->whereNull('conversation_members.visible_until_message_id')
                ->orWhereColumn('conversation_members.visible_until_message_id', '>=', $query->qualifyColumn('id')));
    }

    /**
     * Leave out messages hidden by the user's "Clear chat" / "Delete chat".
     */
    public function scopeNotClearedFor(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')
            ->from('chat_settings')
            ->whereColumn('chat_settings.conversation_id', $query->qualifyColumn('conversation_id'))
            ->where('chat_settings.user_id', $id)
            ->whereColumn('chat_settings.cleared_message_id', '>=', $query->qualifyColumn('id')));
    }

    /* -----------------------------------------------------------------
     |  Helpers
     | -----------------------------------------------------------------
     */

    public function isSentBy(User|int $user): bool
    {
        return (int) $this->sender_id === (int) ($user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Who hears about this message: both people, or everyone in the group.
     *
     * @return list<int>
     */
    public function audienceIds(): array
    {
        if ($this->isGroupMessage()) {
            return array_values(array_unique([
                ...ConversationMember::query()->where('conversation_id', $this->conversation_id)->whereNull('left_at')->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
                (int) $this->sender_id,
            ]));
        }

        return array_values(array_unique([(int) $this->receiver_id, (int) $this->sender_id]));
    }

    /** A message of a group chat (it has no single receiver). */
    public function isGroupMessage(): bool
    {
        return $this->receiver_id === null;
    }

    /**
     * Sent or received by the user; for group messages, sent while they were in the group.
     */
    public function involves(User|int $user): bool
    {
        $id = (int) ($user instanceof User ? $user->getKey() : $user);

        if ((int) $this->sender_id === $id) {
            return true;
        }

        if ($this->isGroupMessage()) {
            return (bool) ConversationMember::query()
                ->where('conversation_id', $this->conversation_id)
                ->where('user_id', $id)
                ->first()?->couldSee((int) $this->getKey());
        }

        return (int) $this->receiver_id === $id;
    }

    public function isDeletedFor(User|int $user): bool
    {
        if ($this->isSentBy($user)) {
            return $this->deleted_for_sender;
        }

        if ($this->isGroupMessage()) {
            $id = $user instanceof User ? $user->getKey() : $user;

            return DB::table('message_hides')->where('message_id', $this->getKey())->where('user_id', $id)->exists();
        }

        return $this->deleted_for_receiver;
    }

    public function hasAttachment(): bool
    {
        return $this->attachment !== null && ! $this->deleted_for_everyone;
    }

    /**
     * A live location that is still being shared.
     */
    public function isLiveLocationActive(): bool
    {
        $meta = $this->attachment_meta ?? [];

        return $this->message_type === self::TYPE_LOCATION
            && ! $this->deleted_for_everyone
            && ($meta['live'] ?? false)
            && empty($meta['stopped_at'])
            && isset($meta['live_until'])
            && now()->lt($meta['live_until']);
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

        // View once media never shows a caption or details in previews (M22).
        if ($this->attachment_meta['view_once'] ?? false) {
            return match ($this->message_type) {
                self::TYPE_VIDEO => '🎥 View once video',
                self::TYPE_VOICE => '🎤 View once voice message',
                default => '📷 View once photo',
            };
        }

        return match ($this->message_type) {
            self::TYPE_IMAGE => ($this->attachment_mime === 'image/gif' ? '👾 ' : '📷 ')
                .($this->message ? str($this->message)->limit($limit) : ($this->attachment_mime === 'image/gif' ? 'GIF' : 'Photo')),
            self::TYPE_STICKER => '💟 Sticker',
            self::TYPE_LOCATION => ($this->attachment_meta['live'] ?? false) ? '📍 Live location' : '📍 Location',
            self::TYPE_SYSTEM => $this->systemText(),
            self::TYPE_POLL => '📊 Poll: '.str((string) ($this->attachment_meta['question'] ?? ''))->limit($limit),
            self::TYPE_CONTACT => '👤 Contact: '.str((string) ($this->attachment_meta['name'] ?? ''))->limit($limit),
            self::TYPE_DOCUMENT => '📄 '.($this->attachment_name ?: 'Document'),
            self::TYPE_VOICE => '🎤 Voice message',
            self::TYPE_VIDEO => '🎥 '.($this->message ? str(self::stripFormatting($this->message))->squish()->limit($limit) : 'Video'),
            self::TYPE_CALL => $this->callPreview(),
            default => (string) str(self::stripFormatting((string) $this->message))->squish()->limit($limit),
        };
    }

    /**
     * Text without WhatsApp-style formatting markers (*bold*, _italic_, ~strike~, `code`).
     */
    public static function stripFormatting(string $text): string
    {
        $text = preg_replace('/```([\s\S]+?)```/u', '$1', $text) ?? $text;
        $text = preg_replace('/`([^`\n]+)`/u', '$1', $text) ?? $text;

        return preg_replace('/(^|\s)[*_~](\S(?:.*?\S)?)[*_~](?=\s|$)/u', '$1$2', $text) ?? $text;
    }

    /**
     * Text of an app notice, e.g. "⏱️ Disappearing messages: 7 days".
     */
    public function systemText(): string
    {
        $meta = $this->attachment_meta ?? [];
        $actor = (string) ($meta['actor']['name'] ?? 'Someone');
        $users = collect($meta['users'] ?? [])->pluck('name')->filter()->implode(', ');

        // Group notices (Phase 4).
        $group = match ($meta['event'] ?? null) {
            'group_created' => "{$actor} created group \"".($meta['name'] ?? '').'"',
            'members_added' => "{$actor} added {$users}",
            'member_removed' => "{$actor} removed {$users}",
            'member_left' => "{$actor} left",
            'member_joined_link' => "{$actor} joined using this group's invite link",
            'name_changed' => "{$actor} changed the group name to \"".($meta['name'] ?? '').'"',
            'description_changed' => "{$actor} changed the group description",
            'avatar_changed' => "{$actor} changed this group's icon",
            'avatar_removed' => "{$actor} deleted this group's icon",
            'settings_changed' => match (true) {
                array_key_exists('only_admins_send', $meta) => "{$actor} changed this group's settings to allow ".($meta['only_admins_send'] ? 'only admins' : 'all members').' to send messages',
                default => "{$actor} changed this group's settings to allow ".(($meta['only_admins_edit'] ?? false) ? 'only admins' : 'all members')." to edit this group's info",
            },
            'group_ended' => "{$actor} deleted this group",
            'community_created' => "{$actor} created the community \"".($meta['name'] ?? '').'"',
            'community_linked' => "{$actor} added this group to the community \"".($meta['name'] ?? '').'"',
            'community_unlinked' => "{$actor} removed this group from the community \"".($meta['name'] ?? '').'"',
            'member_joined_community' => "{$actor} joined from the community",
            'channel_created' => 'Channel created',
            default => null,
        };

        if ($group !== null) {
            return $group;
        }

        if (($meta['event'] ?? null) === 'disappearing') {
            $seconds = (int) ($meta['seconds'] ?? 0);

            return $seconds > 0
                ? '⏱️ Disappearing messages turned on: new messages disappear after '.match ($seconds) {
                    86400 => '24 hours',
                    604800 => '7 days',
                    7776000 => '90 days',
                    default => round($seconds / 3600).' hours',
                }
            : '⏱️ Disappearing messages turned off';
        }

        return '';
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
