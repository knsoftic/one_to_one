<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MessagePolicy
{
    /**
     * View a message / its attachment: participants only, and not after it
     * was deleted for the viewer or for everyone.
     */
    public function view(User $user, Message $message): Response
    {
        if (! $message->involves($user) || $message->isDeletedFor($user) || $message->deleted_for_everyone) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function update(User $user, Message $message): Response
    {
        if (! $message->involves($user) || $message->isDeletedFor($user)) {
            return Response::denyAsNotFound();
        }

        if (! $message->isSentBy($user)) {
            return Response::deny('You can only edit your own messages.');
        }

        if ($message->deleted_for_everyone) {
            return Response::deny('This message was deleted.');
        }

        if ($message->message_type !== Message::TYPE_TEXT) {
            return Response::deny('Only text messages can be edited.');
        }

        if ($this->windowPassed($message, (int) config('chat.edit_window_minutes'))) {
            return Response::deny('This message can no longer be edited.');
        }

        if ($user->hasBlockWith($message->receiver_id)) {
            return Response::deny('You can no longer edit messages in this conversation.');
        }

        return Response::allow();
    }

    public function deleteForMe(User $user, Message $message): Response
    {
        return $message->involves($user) && ! $message->isDeletedFor($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function deleteForEveryone(User $user, Message $message): Response
    {
        if (! $message->involves($user) || $message->isDeletedFor($user)) {
            return Response::denyAsNotFound();
        }

        if (! $message->isSentBy($user)) {
            return Response::deny('You can only delete your own messages for everyone.');
        }

        if ($message->deleted_for_everyone) {
            return Response::deny('This message was already deleted.');
        }

        if ($this->windowPassed($message, (int) config('chat.delete_for_everyone_window_minutes'))) {
            return Response::deny('This message is too old to be deleted for everyone.');
        }

        return Response::allow();
    }

    private function windowPassed(Message $message, int $minutes): bool
    {
        return $minutes > 0 && $message->created_at->lt(now()->subMinutes($minutes));
    }
}
