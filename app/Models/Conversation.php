<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_one_id',
        'user_two_id',
        'last_message_id',
    ];

    protected function casts(): array
    {
        return ['disappearing_seconds' => 'integer'];
    }

    protected static function booted(): void
    {
        // Participants are always stored in ascending order so the unique
        // (user_one_id, user_two_id) index prevents duplicate conversations.
        // Both ids are the same for "Message yourself" (C7).
        static::saving(function (Conversation $conversation) {
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

    /* -----------------------------------------------------------------
     |  Scopes
     | -----------------------------------------------------------------
     */

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $query->where(fn (Builder $q) => $q->where('user_one_id', $id)->orWhere('user_two_id', $id));
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

    /**
     * "Message yourself": notes, links and files a person keeps for themselves (C7).
     */
    public function isSelf(): bool
    {
        return (int) $this->user_one_id === (int) $this->user_two_id;
    }

    public function hasParticipant(User|int $user): bool
    {
        $id = (int) ($user instanceof User ? $user->getKey() : $user);

        return (int) $this->user_one_id === $id || (int) $this->user_two_id === $id;
    }

    public function otherParticipantId(User|int $user): int
    {
        $id = (int) ($user instanceof User ? $user->getKey() : $user);

        return (int) $this->user_one_id === $id ? (int) $this->user_two_id : (int) $this->user_one_id;
    }

    /**
     * The participant who is not $user (uses loaded relations when available).
     */
    public function otherParticipant(User|int $user): ?User
    {
        $otherId = $this->otherParticipantId($user);

        return (int) $this->user_one_id === $otherId
            ? $this->userOne
            : $this->userTwo;
    }
}
