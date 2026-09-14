<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\Call;
use App\Models\Message;
use App\Models\StarredMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * K1 — Calls tab: a person's call history, newest first.
 *
 * Every ended call has a call-history message in its chat; the log follows
 * that message, so "Delete for me", "Clear chat" and locked chats (C9) apply
 * to the call log as well.
 */
class CallLogService
{
    public function __construct(private readonly ChatLockService $lock) {}

    /**
     * @return Builder<Call>
     */
    public function query(User $user): Builder
    {
        return Call::query()
            ->involving($user)
            ->where('status', Call::STATUS_ENDED)
            ->whereNotNull('message_id')
            ->whereHas('message', fn ($q) => $q->visibleTo($user))
            ->whereNotIn('conversation_id', $this->lock->hiddenIds($user));
    }

    /**
     * @param  array<int, string>  $savedNames  phone-book names keyed by user id
     * @return array<string, mixed>
     */
    public function payload(Call $call, User $user, array $savedNames, Request $request): array
    {
        $outgoing = $call->isCaller($user);
        $peer = $outgoing ? $call->callee : $call->caller;
        $peerData = $peer ? (new UserResource($peer))->resolve($request) : null;

        if ($peerData) {
            $peerData['saved_name'] = $savedNames[$peer->id] ?? null;
        }

        return [
            'id' => $call->id,
            'conversation_id' => $call->conversation_id,
            'message_id' => $call->message_id,
            'type' => $call->type,
            'direction' => $outgoing ? 'outgoing' : 'incoming',
            'missed' => ! $outgoing && $call->isMissed(),
            'end_reason' => $call->end_reason,
            'duration' => $call->duration,
            'created_at' => $call->created_at?->toIso8601String(),
            'peer' => $peerData,
        ];
    }

    /** Missed calls the person has not looked at yet (badge on the Calls tab). */
    public function unseenMissed(User $user): int
    {
        return $this->missedMessages($user)->count();
    }

    /** Opening the Calls tab: missed calls are no longer new (also in their chats). */
    public function markMissedSeen(User $user): int
    {
        return $this->missedMessages($user)->update(['seen_at' => now()]);
    }

    /**
     * "Clear call log": every call-history message is deleted for this person only.
     */
    public function clear(User $user): int
    {
        $ids = $this->query($user)->pluck('message_id')->all();

        if ($ids === []) {
            return 0;
        }

        Message::query()->whereIn('id', $ids)->where('sender_id', $user->getKey())->update(['deleted_for_sender' => true]);
        Message::query()->whereIn('id', $ids)->where('receiver_id', $user->getKey())->update(['deleted_for_receiver' => true]);
        StarredMessage::query()->where('user_id', $user->getKey())->whereIn('message_id', $ids)->delete();

        return count($ids);
    }

    /**
     * @return Builder<Message>
     */
    private function missedMessages(User $user): Builder
    {
        return Message::query()
            ->where('receiver_id', $user->getKey())
            ->where('message_type', Message::TYPE_CALL)
            ->whereNull('seen_at')
            ->visibleTo($user)
            ->whereNotIn('conversation_id', $this->lock->hiddenIds($user));
    }
}
