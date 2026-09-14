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

    protected $fillable = ['user_id', 'name', 'position'];

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
            'conversation_ids' => $this->conversations->modelKeys(),
        ];
    }
}
