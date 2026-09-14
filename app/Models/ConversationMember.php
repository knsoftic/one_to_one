<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who is (or was) in a group chat.
 */
class ConversationMember extends Model
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'added_by',
        'joined_at',
        'left_at',
        'visible_from_message_id',
        'visible_until_message_id',
        'last_read_message_id',
        'last_delivered_message_id',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'visible_from_message_id' => 'integer',
            'visible_until_message_id' => 'integer',
            'last_read_message_id' => 'integer',
            'last_delivered_message_id' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<ConversationMember>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('left_at');
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    public function isAdmin(): bool
    {
        return $this->isActive() && $this->role === self::ROLE_ADMIN;
    }

    /** Was this person in the group when message $messageId was sent? */
    public function couldSee(int $messageId): bool
    {
        return $messageId >= $this->visible_from_message_id
            && ($this->visible_until_message_id === null || $messageId <= $this->visible_until_message_id);
    }
}
