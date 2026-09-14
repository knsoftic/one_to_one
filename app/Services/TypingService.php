<?php

namespace App\Services;

use App\Events\UserTyping;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived "typing…" / "recording audio…" state. Broadcast instantly over
 * WebSockets and kept in the cache so the polling fallback can report it too.
 */
class TypingService
{
    public const ACTION_TYPING = 'typing';

    public const ACTION_RECORDING = 'recording';

    public const ACTIONS = [self::ACTION_TYPING, self::ACTION_RECORDING];

    public function set(Conversation $conversation, User $user, bool $active, string $action = self::ACTION_TYPING): void
    {
        // Nobody to tell in "Message yourself", a broadcast list or a channel (G11).
        if ($conversation->isSelf() || $conversation->isBroadcast() || $conversation->isChannel()) {
            return;
        }

        $key = $this->key($conversation->getKey(), $user->getKey());

        if ($active) {
            Cache::put($key, $action, now()->addSeconds(config('chat.typing_ttl_seconds')));
        } else {
            Cache::forget($key);
        }

        $recipients = $conversation->isGroup()
            ? array_values(array_diff($conversation->activeMemberIds(), [(int) $user->getKey()]))
            : $conversation->otherParticipantId($user);

        if ($recipients === []) {
            return;
        }

        broadcast(new UserTyping(
            $conversation->getKey(),
            $user->getKey(),
            $recipients,
            $active,
            $action,
        ))->toOthers();
    }

    /**
     * Is the other participant (not $viewer) typing or recording in the conversation?
     */
    public function isOtherTyping(Conversation $conversation, User $viewer): bool
    {
        return $this->otherActivity($conversation, $viewer) !== null;
    }

    /**
     * What the other participant is doing: "typing", "recording" or null.
     */
    public function otherActivity(Conversation $conversation, User $viewer): ?string
    {
        return $this->activeTyper($conversation, $viewer)['action'] ?? null;
    }

    /**
     * Someone other than $viewer typing or recording: ['user_id' => …, 'action' => …] or null.
     *
     * @return array{user_id: int, action: string}|null
     */
    public function activeTyper(Conversation $conversation, User $viewer): ?array
    {
        $others = $conversation->isGroup()
            ? array_values(array_diff($conversation->activeMemberIds(), [(int) $viewer->getKey()]))
            : [$conversation->otherParticipantId($viewer)];

        foreach ($others as $userId) {
            if ($action = $this->activityOf($conversation->getKey(), $userId)) {
                return ['user_id' => $userId, 'action' => $action];
            }
        }

        return null;
    }

    private function activityOf(int $conversationId, int $userId): ?string
    {
        $value = Cache::get($this->key($conversationId, $userId));

        return match (true) {
            $value === null || $value === false => null,
            in_array($value, self::ACTIONS, true) => $value,
            default => self::ACTION_TYPING,
        };
    }

    private function key(int $conversationId, int $userId): string
    {
        return "chat:typing:{$conversationId}:{$userId}";
    }
}
