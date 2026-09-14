<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatLockService;
use Illuminate\Auth\Access\Response;

class ConversationPolicy
{
    /**
     * Only the two participants can ever access a conversation.
     * (Administrators get no special access to private chats.)
     */
    public function view(User $user, Conversation $conversation): Response
    {
        if (! $conversation->hasParticipant($user)) {
            return Response::denyAsNotFound();
        }

        // A locked chat opens only after the secret code (C9).
        return app(ChatLockService::class)->canOpen($user, (int) $conversation->getKey())
            ? Response::allow()
            : Response::denyWithStatus(423, 'This chat is locked.');
    }

    /**
     * Being in the chat, without seeing its messages (page shell, "mark as read" from a phone notification).
     */
    public function participate(User $user, Conversation $conversation): Response
    {
        return $conversation->hasParticipant($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Participants can send messages unless either one has blocked the other.
     */
    public function sendMessage(User $user, Conversation $conversation): Response
    {
        if (! $conversation->hasParticipant($user)) {
            return Response::denyAsNotFound();
        }

        // A broadcast list (G9): only its owner writes to it.
        if ($conversation->isBroadcast()) {
            return $conversation->isActiveMember($user) ? Response::allow() : Response::denyAsNotFound();
        }

        // Channels (G11): only admins post updates.
        if ($conversation->isChannel()) {
            return $conversation->ended_at === null && $conversation->isAdmin($user)
                ? Response::allow()
                : Response::deny('Only channel admins can post updates.');
        }

        // Groups (Phase 4): people in the group; only admins when the group allows only admins (G5).
        if ($conversation->isGroup()) {
            if ($conversation->ended_at !== null || ! $conversation->isActiveMember($user)) {
                return Response::deny("You can't send messages to this group because you're no longer a member.");
            }

            return $conversation->only_admins_send && ! $conversation->isAdmin($user)
                ? Response::deny('Only admins can send messages to this group.')
                : Response::allow();
        }

        $otherId = $conversation->otherParticipantId($user);

        if ($user->hasBlocked($otherId)) {
            return Response::deny('You blocked this user. Unblock them to send messages.');
        }

        if ($user->isBlockedBy($otherId)) {
            return Response::deny('You can no longer send messages to this user.');
        }

        return Response::allow();
    }
}
