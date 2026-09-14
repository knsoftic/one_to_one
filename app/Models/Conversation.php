<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /** Two people (or "Message yourself"). */
    public const TYPE_DIRECT = 'direct';

    /** A group chat (Phase 4): its people are in conversation_members. */
    public const TYPE_GROUP = 'group';

    /** A broadcast list (G9): only its owner is in it; recipients are in broadcast_recipients. */
    public const TYPE_BROADCAST = 'broadcast';

    /** A channel (G11): admins post updates, followers read and react; all are in conversation_members. */
    public const TYPE_CHANNEL = 'channel';

    protected $fillable = [
        'type',
        'user_one_id',
        'user_two_id',
        'last_message_id',
        'name',
        'description',
        'avatar',
        'created_by',
        'only_admins_send',
        'only_admins_edit',
        'invite_token',
        'ended_at',
        'community_id',
        'is_announcement',
    ];

    protected $attributes = [
        'type' => self::TYPE_DIRECT,
    ];

    protected function casts(): array
    {
        return [
            'disappearing_seconds' => 'integer',
            'only_admins_send' => 'boolean',
            'only_admins_edit' => 'boolean',
            'is_announcement' => 'boolean',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Participants are always stored in ascending order so the unique
        // (user_one_id, user_two_id) index prevents duplicate conversations.
        // Both ids are the same for "Message yourself" (C7). Groups have neither.
        static::saving(function (Conversation $conversation) {
            if ($conversation->hasMembers()) {
                $conversation->user_one_id = null;
                $conversation->user_two_id = null;

                return;
            }

            $one = (int) $conversation->user_one_id;
            $two = (int) $conversation->user_two_id;

            if ($one < 1 || $two < 1) {
                throw new InvalidArgumentException('A conversation requires two users.');
            }

            if ($one > $two) {
                $conversation->user_one_id = $two;
                $conversation->user_two_id = $one;
            }
        });
    }

    /* -----------------------------------------------------------------
     |  Relationships
     | -----------------------------------------------------------------
     */

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /** People who are or were in a group. */
    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    /** People a broadcast list sends to (G9). */
    public function broadcastRecipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'broadcast_recipients')->withPivot('created_at');
    }

    /** The community this group belongs to (G10). */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* -----------------------------------------------------------------
     |  Scopes
     | -----------------------------------------------------------------
     */

    /**
     * Chats of a user: their direct chats and every group they are or were in.
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->where(fn (Builder $q) => $q
            ->where('user_one_id', $id)
            ->orWhere('user_two_id', $id)
            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('conversation_members')
                ->whereColumn('conversation_members.conversation_id', 'conversations.id')
                ->where('conversation_members.user_id', $id)));
    }

    public function scopeBetween(Builder $query, User|int $a, User|int $b): Builder
    {
        [$one, $two] = static::orderedPair($a, $b);

        return $query->where('user_one_id', $one)->where('user_two_id', $two);
    }

    /* -----------------------------------------------------------------
     |  Helpers
     | -----------------------------------------------------------------
     */

    /**
     * @return array{0:int,1:int}
     */
    public static function orderedPair(User|int $a, User|int $b): array
    {
        $a = $a instanceof User ? (int) $a->getKey() : $a;
        $b = $b instanceof User ? (int) $b->getKey() : $b;

        return [min($a, $b), max($a, $b)];
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    public function isBroadcast(): bool
    {
        return $this->type === self::TYPE_BROADCAST;
    }

    public function isChannel(): bool
    {
        return $this->type === self::TYPE_CHANNEL;
    }

    /** Groups, broadcast lists and channels keep their people in conversation_members. */
    public function hasMembers(): bool
    {
        return $this->isGroup() || $this->isBroadcast() || $this->isChannel();
    }

    /**
     * "Message yourself": notes, links and files a person keeps for themselves (C7).
     */
    public function isSelf(): bool
    {
        return ! $this->hasMembers() && $this->user_one_id !== null && (int) $this->user_one_id === (int) $this->user_two_id;
    }

    /**
     * In the chat: one of the two people, or someone who is or was in the group
     * (former members keep reading what they saw while they were in it).
     */
    public function hasParticipant(User|int $user): bool
    {
        $id = (int) ($user instanceof User ? $user->getKey() : $user);

        if ($this->hasMembers()) {
            return $this->memberFor($id) !== null;
        }

        return (int) $this->user_one_id === $id || (int) $this->user_two_id === $id;
    }

    /** The group membership of $user (including a past one). */
    public function memberFor(User|int $user): ?ConversationMember
    {
        $id = (int) ($user instanceof User ? $user->getKey() : $user);

        if ($this->relationLoaded('members')) {
            return $this->members->first(fn (ConversationMember $member) => (int) $member->user_id === $id);
        }

        return $this->members()->where('user_id', $id)->first();
    }

    /** Someone currently in the group. */
    public function isActiveMember(User|int $user): bool
    {
        return (bool) $this->memberFor($user)?->isActive();
    }

    public function isAdmin(User|int $user): bool
    {
        return (bool) $this->memberFor($user)?->isAdmin();
    }

    /**
     * Ids of the people currently in the group (for broadcasts and notifications).
     *
     * @return list<int>
     */
    public function activeMemberIds(): array
    {
        return $this->activeMembers()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Ids of everyone who should hear about this chat changing. */
    public function audienceIds(): array
    {
        return $this->hasMembers()
            ? $this->activeMemberIds()
            : array_values(array_unique([(int) $this->user_one_id, (int) $this->user_two_id]));
    }

    public function groupAvatarUrl(): ?string
    {
        return $this->avatar ? asset('storage/'.$this->avatar) : null;
    }

    /** Up to two letters of the group name, like user initials. */
    public function groupInitials(): string
    {
        $words = preg_split('/\s+/u', trim((string) $this->name)) ?: [];
        $letters = collect($words)->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');

        return $letters !== '' ? $letters : '#';
    }

    public function groupHue(): int
    {
        return ((int) $this->getKey() * 47) % 360;
    }

    public function otherParticipantId(User|int $user): int
    {
        if ($this->hasMembers()) {
            return 0;
        }

        $id = (int) ($user instanceof User ? $user->getKey() : $user);

        return (int) $this->user_one_id === $id ? (int) $this->user_two_id : (int) $this->user_one_id;
    }

    /**
     * The participant who is not $user (uses loaded relations when available).
     */
    public function otherParticipant(User|int $user): ?User
    {
        if ($this->hasMembers()) {
            return null;
        }

        $otherId = $this->otherParticipantId($user);

        return (int) $this->user_one_id === $otherId
            ? $this->userOne
            : $this->userTwo;
    }
}
