<?php

namespace App\Services;

use App\Events\ChatSettingsUpdated;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\StarredMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 2 — how a person keeps a chat in their own list: pin (C1), mute (C2),
 * archive (C3), mark unread/read (C4), clear and delete (C5).
 */
class ChatSettingsService
{
    public function __construct(private readonly MessageService $messages) {}

    public function for(User $user, Conversation $conversation): ChatSetting
    {
        return ChatSetting::query()->firstOrNew([
            'conversation_id' => $conversation->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }

    /**
     * Whether the user muted the chat (used before notifying them).
     */
    public function isMuted(User|int $user, Conversation|int $conversation): bool
    {
        return ChatSetting::query()
            ->where('user_id', $user instanceof User ? $user->getKey() : $user)
            ->where('conversation_id', $conversation instanceof Conversation ? $conversation->getKey() : $conversation)
            ->where('muted_until', '>', now())
            ->exists();
    }

    /**
     * Apply several changes at once (from the chat menu).
     *
     * @param  array{pinned?: bool, archived?: bool, muted?: ?string, unread?: bool, favorite?: bool}  $changes
     *
     * @throws RuntimeException when a fourth chat would be pinned
     */
    public function update(User $user, Conversation $conversation, array $changes): ChatSetting
    {
        $setting = $this->for($user, $conversation);

        if (array_key_exists('pinned', $changes)) {
            if ($changes['pinned'] && $setting->pinned_at === null) {
                $pinned = ChatSetting::query()->where('user_id', $user->getKey())->whereNotNull('pinned_at')->count();
                if ($pinned >= ChatSetting::MAX_PINNED) {
                    throw new RuntimeException('You can pin up to '.ChatSetting::MAX_PINNED.' chats.');
                }
                $setting->pinned_at = now();
                // A pinned chat is always in the main list.
                $setting->archived_at = null;
            } elseif (! $changes['pinned']) {
                $setting->pinned_at = null;
            }
        }

        if (array_key_exists('archived', $changes)) {
            $setting->archived_at = $changes['archived'] ? ($setting->archived_at ?? now()) : null;
            // Archived chats are not pinned.
            if ($changes['archived']) {
                $setting->pinned_at = null;
            }
        }

        if (array_key_exists('muted', $changes)) {
            $setting->muted_until = $changes['muted'] ? ChatSetting::muteEnd($changes['muted']) : null;
        }

        // Locked chats (C9) move to "Locked chats": not pinned or archived.
        if (array_key_exists('locked', $changes)) {
            $setting->locked_at = $changes['locked'] ? ($setting->locked_at ?? now()) : null;
            if ($changes['locked']) {
                $setting->pinned_at = null;
                $setting->archived_at = null;
            }
            app(ChatLockService::class)->forget();
        }

        if (array_key_exists('favorite', $changes)) {
            $setting->favorite_at = $changes['favorite'] ? ($setting->favorite_at ?? now()) : null;
        }

        if (array_key_exists('unread', $changes)) {
            if ($changes['unread']) {
                $setting->marked_unread = true;
            } else {
                // "Mark as read" reads everything that is waiting, like opening the chat.
                $setting->marked_unread = false;
                $this->messages->markSeen($conversation, $user);
            }
        }

        $setting->save();
        broadcast(new ChatSettingsUpdated($user->getKey(), $conversation->getKey()))->toOthers();

        return $setting;
    }

    /**
     * Hide every message so far for this user only ("Clear chat"). Starred
     * messages are unstarred unless they should be kept.
     */
    public function clear(User $user, Conversation $conversation, bool $keepStarred = false): ChatSetting
    {
        $setting = $this->for($user, $conversation);
        $lastId = (int) $conversation->messages()->max('id');

        DB::transaction(function () use ($setting, $user, $conversation, $lastId, $keepStarred) {
            $setting->forceFill([
                'cleared_message_id' => max($lastId, (int) $setting->cleared_message_id),
                'cleared_at' => now(),
                'deleted_at' => null,
                'marked_unread' => false,
            ])->save();

            if (! $keepStarred) {
                StarredMessage::query()
                    ->where('user_id', $user->getKey())
                    ->whereIn('message_id', $conversation->messages()->where('id', '<=', $lastId)->select('id'))
                    ->delete();
            }
        });

        broadcast(new ChatSettingsUpdated($user->getKey(), $conversation->getKey()))->toOthers();

        return $setting;
    }

    /**
     * "Delete chat": clear it and take it out of the list until a new message arrives.
     */
    public function delete(User $user, Conversation $conversation): ChatSetting
    {
        // Like WhatsApp: exit a group before deleting it (G8).
        if ($conversation->isGroup() && $conversation->ended_at === null && $conversation->isActiveMember($user)) {
            throw new RuntimeException('Exit the group before deleting it.');
        }
        if ($conversation->isChannel() && $conversation->isActiveMember($user)) {
            throw new RuntimeException('Unfollow the channel before deleting it.');
        }

        $setting = $this->clear($user, $conversation);

        $setting->forceFill([
            'deleted_at' => now(),
            'pinned_at' => null,
            'archived_at' => null,
            'favorite_at' => null,
        ])->save();

        return $setting;
    }
}
