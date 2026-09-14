<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationMember;

/**
 * The type of conversations, remembered for one request (message payloads need
 * to know whether a message belongs to a channel or a community's announcements
 * without loading its conversation).
 */
class ConversationTypes
{
    /** @var array<int, array{type: ?string, announcement: bool}> */
    private array $conversations = [];

    /** @var array<string, bool> */
    private array $admins = [];

    public function isChannel(int $conversationId): bool
    {
        return $this->typeOf($conversationId) === Conversation::TYPE_CHANNEL;
    }

    public function typeOf(int $conversationId): ?string
    {
        return $this->info($conversationId)['type'];
    }

    /** A community's announcement group (G10). */
    public function isAnnouncement(int $conversationId): bool
    {
        return $this->info($conversationId)['announcement'];
    }

    public function isAdmin(int $conversationId, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->admins["{$conversationId}:{$userId}"] ??= ConversationMember::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->where('role', ConversationMember::ROLE_ADMIN)
            ->exists();
    }

    /**
     * Who is in it stays private: followers of a channel (G11), and members of a
     * community's announcements who aren't its admins (G10).
     */
    public function hidesMembersFrom(int $conversationId, ?int $viewerId): bool
    {
        return $this->isChannel($conversationId)
            || ($this->isAnnouncement($conversationId) && ! $this->isAdmin($conversationId, $viewerId));
    }

    /**
     * @return array{type: ?string, announcement: bool}
     */
    private function info(int $conversationId): array
    {
        if (! array_key_exists($conversationId, $this->conversations)) {
            $row = Conversation::query()->whereKey($conversationId)->first(['type', 'is_announcement']);
            $this->conversations[$conversationId] = [
                'type' => $row?->type ?: null,
                'announcement' => (bool) $row?->is_announcement,
            ];
        }

        return $this->conversations[$conversationId];
    }
}
