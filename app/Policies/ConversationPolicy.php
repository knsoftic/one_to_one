<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ConversationPolicy
{
    /**
     * Only the two participants can ever access a conversation.
     * (Administrators get no special access to private chats.)
     */
    public function view(User $user, Conversation $conversation): Response
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
