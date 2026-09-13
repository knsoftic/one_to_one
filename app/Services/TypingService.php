<?php

namespace App\Services;

use App\Events\UserTyping;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived typing state. Broadcast instantly over WebSockets and kept in
 * the cache so the polling fallback can report it too.
 */
class TypingService
{
    public function set(Conversation $conversation, User $user, bool $typing): void
    {
        $key = $this->key($conversation->getKey(), $user->getKey());

        if ($typing) {
            Cache::put($key, true, now()->addSeconds(config('chat.typing_ttl_seconds')));
        } else {
            Cache::forget($key);
        }

        broadcast(new UserTyping(
            $conversation->getKey(),
            $user->getKey(),
            $conversation->otherParticipantId($user),
            $typing,
        ))->toOthers();
    }

    /**
     * Is the other participant (not $viewer) typing in the conversation?
     */
    public function isOtherTyping(Conversation $conversation, User $viewer): bool
    {
        return Cache::has($this->key($conversation->getKey(), $conversation->otherParticipantId($viewer)));
    }

    private function key(int $conversationId, int $userId): string
    {
        return "chat:typing:{$conversationId}:{$userId}";
    }
}
