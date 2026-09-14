<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use App\Services\ChatLockService;
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

        return app(ChatLockService::class)->canOpen($user, (int) $message->conversation_id)
            ? Response::allow()
            : Response::denyWithStatus(423, 'This chat is locked.');
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

        if ($denied = $this->groupDenial($user, $message)) {
            return $denied;
        }

        if ($message->conversation?->isBroadcast()) {
            return Response::deny('Messages sent to a broadcast list cannot be edited.');
        }

        if (! $message->isGroupMessage() && $user->hasBlockWith($message->receiver_id)) {
            return Response::deny('You can no longer edit messages in this conversation.');
        }

        return Response::allow();
    }

    /**
     * Forward: any visible message except call history and deleted messages.
     */
    /**
     * Vote in a poll: both people, unless one has blocked the other.
     */
    public function vote(User $user, Message $message): Response
    {
        $view = $this->view($user, $message);
        if ($view->denied()) {
            return $view;
        }

        if ($message->message_type !== Message::TYPE_POLL) {
            return Response::deny('This message is not a poll.');
        }

        if ($denied = $this->groupDenial($user, $message)) {
            return $denied;
        }

        if (! $message->isGroupMessage() && $user->hasBlockWith($message->isSentBy($user) ? $message->receiver_id : $message->sender_id)) {
            return Response::deny('You can no longer vote in this conversation.');
        }

        return Response::allow();
    }

    /**
     * Update or stop a live location: only its sender, while it is being shared.
     */
    public function updateLocation(User $user, Message $message): Response
    {
        $view = $this->view($user, $message);
        if ($view->denied()) {
            return $view;
        }

        if (! $message->isSentBy($user) || $message->message_type !== Message::TYPE_LOCATION) {
            return Response::deny('Only the person sharing a location can update it.');
        }

        return $message->isLiveLocationActive()
            ? Response::allow()
            : Response::deny('This live location has ended.');
    }

    public function forward(User $user, Message $message): Response
    {
        $view = $this->view($user, $message);
        if ($view->denied()) {
            return $view;
        }

        if ($message->attachment_meta['view_once'] ?? false) {
            return Response::deny('View once messages cannot be forwarded.');
        }

        return match ($message->message_type) {
            Message::TYPE_CALL => Response::deny('Call history cannot be forwarded.'),
            Message::TYPE_SYSTEM => Response::deny('Chat notices cannot be forwarded.'),
            default => Response::allow(),
        };
    }

    /**
     * React with an emoji: visible messages (not call history), and not while either side blocks the other.
     */
    public function react(User $user, Message $message): Response
    {
        $view = $this->view($user, $message);
        if ($view->denied()) {
            return $view;
        }

        if (in_array($message->message_type, [Message::TYPE_CALL, Message::TYPE_SYSTEM], true)) {
            return Response::deny('You cannot react to this message.');
        }

        if ($denied = $this->groupDenial($user, $message)) {
            return $denied;
        }

        if ($message->conversation?->isBroadcast()) {
            return Response::deny('Nobody else is in a broadcast list to see reactions.');
        }

        if (! $message->isGroupMessage() && $user->hasBlockWith($message->isSentBy($user) ? $message->receiver_id : $message->sender_id)) {
            return Response::deny('You can no longer react in this conversation.');
        }

        return Response::allow();
    }

    /**
     * Pin to the top of the chat: same rules as reacting.
     */
    public function pin(User $user, Message $message): Response
    {
        $view = $this->view($user, $message);
        if ($view->denied()) {
            return $view;
        }

        if (in_array($message->message_type, [Message::TYPE_CALL, Message::TYPE_SYSTEM], true)) {
            return Response::deny($message->message_type === Message::TYPE_CALL ? 'Call history cannot be pinned.' : 'Chat notices cannot be pinned.');
        }

        if ($denied = $this->groupDenial($user, $message)) {
            return $denied;
        }

        // Pinning changes the group for everyone: admins only when info is admins-only (G5).
        if ($message->isGroupMessage() && $message->conversation->only_admins_edit && ! $message->conversation->isAdmin($user)) {
            return Response::deny($message->conversation->isChannel() ? 'Only channel admins can pin updates.' : 'Only admins can pin messages in this group.');
        }

        if (! $message->isGroupMessage() && $user->hasBlockWith($message->isSentBy($user) ? $message->receiver_id : $message->sender_id)) {
            return Response::deny('You can no longer pin messages in this conversation.');
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

        if ($message->message_type === Message::TYPE_CALL) {
            return Response::deny('Call history can only be deleted for you.');
        }

        if ($message->message_type === Message::TYPE_SYSTEM) {
            return Response::deny('Chat notices can only be deleted for you.');
        }

        if ($denied = $this->groupDenial($user, $message)) {
            return $denied;
        }

        if ($this->windowPassed($message, (int) config('chat.delete_for_everyone_window_minutes'))) {
            return Response::deny('This message is too old to be deleted for everyone.');
        }

        return Response::allow();
    }

    /**
     * Former members read a group's past messages but cannot change them.
     */
    private function groupDenial(User $user, Message $message): ?Response
    {
        if (! $message->isGroupMessage()) {
            return null;
        }

        $group = $message->conversation;
        if ($group?->isBroadcast()) {
            return $group->isActiveMember($user) ? null : Response::denyAsNotFound();
        }

        if ($group?->isChannel()) {
            return $group->isActiveMember($user) ? null : Response::deny('Follow the channel first.');
        }

        return $group && ($group->ended_at !== null || ! $group->isActiveMember($user))
            ? Response::deny("You're no longer a member of this group.")
            : null;
    }

    private function windowPassed(Message $message, int $minutes): bool
    {
        return $minutes > 0 && $message->created_at->lt(now()->subMinutes($minutes));
    }
}
