<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A person's own chat list, e.g. "Family" or "Work" (C6).
 */
class ChatList extends Model
{
    public const MAX_PER_USER = 20;

    public const MAX_NAME = 30;

    /** X8 — colours that turn lists into labels. */
    public const COLORS = ['indigo', 'green', 'amber', 'red', 'pink', 'sky', 'teal', 'slate'];

    protected $fillable = ['user_id', 'name', 'color', 'position'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'chat_list_items')->withPivot('created_at');
    }

    /**
     * @return array{id: int, name: string, conversation_ids: list<int>}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'conversation_ids' => $this->conversations->modelKeys(),
        ];
    }
}
