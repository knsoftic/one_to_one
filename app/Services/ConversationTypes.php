<?php

namespace App\Services;

use App\Models\Conversation;

/**
 * The type of conversations, remembered for one request (message payloads need
 * to know whether a message belongs to a channel without loading its conversation).
 */
class ConversationTypes
{
    /** @var array<int, string> */
    private array $types = [];

    public function isChannel(int $conversationId): bool
    {
        return $this->typeOf($conversationId) === Conversation::TYPE_CHANNEL;
    }

    public function typeOf(int $conversationId): ?string
    {
        if (! array_key_exists($conversationId, $this->types)) {
            $this->types[$conversationId] = (string) Conversation::query()->whereKey($conversationId)->value('type');
        }

        return $this->types[$conversationId] ?: null;
    }
}
