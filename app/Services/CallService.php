<?php

namespace App\Services;

use App\Events\CallSignalSent;
use App\Events\CallStarted;
use App\Events\CallUpdated;
use App\Events\MessageSent;
use App\Jobs\SendCallPush;
use App\Models\Call;
use App\Models\CallRoomParticipant;
use App\Models\CallSignal;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One-to-one voice and video calls.
 *
 * The server only coordinates: who is calling whom, which device answered,
 * and relaying WebRTC signaling. Audio and video flow directly between the
 * two devices (or through the TURN server).
 *
 * Lifecycle: ringing → ongoing → ended (completed, declined, missed,
 * cancelled, busy or failed). Every ended call leaves a call-history message
 * in the conversation, like WhatsApp.
 */
class CallService
{
    public function __construct(private readonly PushService $push) {}

    /**
     * Start a call from $caller's device $clientId.
     *
     * @throws HttpException 409 when the caller is already in a call
     */
    public function start(User $caller, Conversation $conversation, string $type, string $clientId): Call
    {
        if ($conversation->isSelf()) {
            throw new HttpException(422, 'You cannot call yourself.');
        }

        if ($conversation->isGroup()) {
            throw new HttpException(422, 'Start a group call from the group instead.');
        }

        if ($conversation->isBroadcast()) {
            throw new HttpException(422, 'A broadcast list cannot be called.');
        }

        if ($conversation->isChannel()) {
            throw new HttpException(422, 'A channel cannot be called.');
        }

        $this->expireStale();

        $calleeId = $conversation->otherParticipantId($caller);

        $call = DB::transaction(function () use ($caller, $conversation, $type, $clientId, $calleeId) {
            // Serialize call attempts involving either user (e.g. both calling each other at once).
            User::query()->whereKey([$caller->getKey(), $calleeId])->lockForUpdate()->get(['id']);

            if (app(CallRoomService::class)->isBusy($caller)) {
                throw new HttpException(409, 'You are already in a call.');
            }

            return Call::create([
                'conversation_id' => $conversation->getKey(),
                'caller_id' => $caller->getKey(),
                'callee_id' => $calleeId,
                'type' => $type,
                'status' => Call::STATUS_RINGING,
                'caller_client' => $clientId,
                'caller_seen_at' => now(),
            ]);
        });

        $calleeBusy = Call::query()->active()->involving($calleeId)->whereKeyNot($call->getKey())->exists()
            || CallRoomParticipant::query()->joined()->where('user_id', $calleeId)->whereHas('room', fn ($q) => $q->active())->exists();

        if ($calleeBusy) {
            return $this->finish($call, Call::REASON_BUSY, null);
        }

        $call->load(['caller', 'callee']);
        broadcast(new CallStarted($call));

        if ($this->push->enabled()) {
            SendCallPush::dispatchAfterResponse($call->getKey(), SendCallPush::KIND_INCOMING);
        }

        return $call;
    }

    /**
     * A callee device is showing the incoming call ("Ringing…" for the caller).
     */
    public function markRinging(Call $call, User $user): Call
    {
        if ($call->isCaller($user) || ! $call->isRinging() || $call->ringing_at !== null) {
            return $call;
        }

        $updated = Call::query()->whereKey($call->getKey())->whereNull('ringing_at')
            ->where('status', Call::STATUS_RINGING)
            ->update(['ringing_at' => now(), 'callee_seen_at' => now()]);

        $call->refresh();

        if ($updated) {
            broadcast(new CallUpdated($call));
        }

        return $call;
    }

    /**
     * The callee answers on device $clientId. Other devices of the callee stop ringing.
     *
     * @throws HttpException 409 when the call is no longer ringing
     */
    public function accept(Call $call, User $callee, string $clientId): Call
    {
        $call = DB::transaction(function () use ($call, $callee, $clientId) {
            /** @var Call $locked */
            $locked = Call::query()->lockForUpdate()->findOrFail($call->getKey());

            if ($locked->isCaller($callee)) {
                throw new HttpException(403, 'Only the person being called can answer.');
            }

            if (! $locked->isRinging()) {
                throw new HttpException(409, $locked->isOngoing() ? 'The call was answered on another device.' : 'The call has ended.');
            }

            $locked->forceFill([
                'status' => Call::STATUS_ONGOING,
                'answered_at' => now(),
                'ringing_at' => $locked->ringing_at ?? now(),
                'callee_client' => $clientId,
                'callee_seen_at' => now(),
                'caller_seen_at' => now(),
            ])->save();

            return $locked;
        });

        $call->load(['caller', 'callee']);
        broadcast(new CallUpdated($call));

        if ($this->push->enabled()) {
            SendCallPush::dispatchAfterResponse($call->getKey(), SendCallPush::KIND_STATE);
        }

        // Answering an invite to a group call (K6) joins it.
        if ($call->call_room_id) {
            app(CallRoomService::class)->join($call->room, $callee, $clientId);
        }

        return $call;
    }

    /**
     * The callee rejects a ringing call.
     */
    public function decline(Call $call, User $callee): Call
    {
        if ($call->isCaller($callee)) {
            throw new HttpException(403, 'Only the person being called can decline.');
        }

        return $call->isRinging() ? $this->finish($call, Call::REASON_DECLINED, $callee) : $call;
    }

    /**
     * Hang up. Ringing calls are cancelled by the caller (or missed after the ring
     * timeout) and declined by the callee; ongoing calls are completed.
     */
    public function hangUp(Call $call, User $user, ?string $reason = null): Call
    {
        if (! $call->isActive()) {
            return $call;
        }

        // In a group call (K6) hanging up leaves it; the others stay connected.
        if ($call->call_room_id && $call->room?->isActive() && $call->room->participantFor($user)?->isJoined()) {
            app(CallRoomService::class)->leave($call->room, $user);

            return $call->fresh();
        }

        if ($call->isRinging()) {
            $endReason = match (true) {
                ! $call->isCaller($user) => Call::REASON_DECLINED,
                $reason === 'no_answer' => Call::REASON_MISSED,
                $reason === 'failed' => Call::REASON_FAILED,
                default => Call::REASON_CANCELLED,
            };

            return $this->finish($call, $endReason, $user);
        }

        $endReason = $reason === 'failed' && ! $this->wasConnected($call) ? Call::REASON_FAILED : Call::REASON_COMPLETED;

        return $this->finish($call, $endReason, $user);
    }

    /**
     * A camera was turned on during a voice call (K2): it becomes a video call
     * (also in the call history).
     *
     * @throws HttpException 409 when the call is not in progress
     */
    public function switchToVideo(Call $call, User $user): Call
    {
        if (! $call->isOngoing()) {
            throw new HttpException(409, 'The call is not in progress.');
        }

        if ($call->type !== Call::TYPE_VIDEO) {
            $call->forceFill(['type' => Call::TYPE_VIDEO])->save();
            $call->load(['caller', 'callee']);
            broadcast(new CallUpdated($call));
        }

        $this->touch($call, $user);

        return $call;
    }

    /**
     * Relay a WebRTC offer, answer or ICE candidate to the other participant.
     *
     * @throws HttpException 409 when the call is over
     */
    public function signal(Call $call, User $sender, string $fromClient, string $type, string $payload, ?string $toClient): CallSignal
    {
        if (! $call->isActive()) {
            throw new HttpException(409, 'The call has ended.');
        }

        $signal = CallSignal::create([
            'call_id' => $call->getKey(),
            'sender_id' => $sender->getKey(),
            'recipient_id' => $call->otherParticipantId($sender),
            'from_client' => $fromClient,
            'to_client' => $toClient,
            'type' => $type,
            'payload' => $payload,
        ]);

        $this->touch($call, $sender);
        broadcast(new CallSignalSent($signal));

        return $signal;
    }

    /**
     * Signals addressed to $user's device $clientId after signal $afterId.
     *
     * @return Collection<int, CallSignal>
     */
    public function signalsFor(Call $call, User $user, string $clientId, int $afterId = 0): Collection
    {
        return $call->signals()
            ->where('recipient_id', $user->getKey())
            ->where('id', '>', $afterId)
            ->where(fn ($q) => $q->whereNull('to_client')->orWhere('to_client', $clientId))
            ->orderBy('id')
            ->limit(200)
            ->get();
    }

    /**
     * The device taking part is still there (keeps an ongoing call from being closed as stale).
     */
    public function touch(Call $call, User $user): void
    {
        if ($call->isActive()) {
            $call->forceFill([$call->isCaller($user) ? 'caller_seen_at' : 'callee_seen_at' => now()])->saveQuietly();
        }
    }

    /**
     * Calls still ringing or in progress for a user (to restore the call screen after
     * a reload or when the app was opened from an incoming-call notification).
     *
     * @return Collection<int, Call>
     */
    public function activeFor(User $user): Collection
    {
        $this->expireStale();

        return Call::query()->active()->involving($user)->with(['caller', 'callee'])->latest('id')->limit(5)->get();
    }

    /**
     * Close calls nobody answered in time and calls whose devices disappeared.
     *
     * @return int number of calls closed
     */
    public function expireStale(): int
    {
        $ringDeadline = now()->subSeconds((int) config('chat.calls.ring_timeout_seconds', 45) + 15);
        $staleDeadline = now()->subSeconds((int) config('chat.calls.stale_after_seconds', 90));

        $calls = Call::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', Call::STATUS_RINGING)->where('created_at', '<', $ringDeadline))
                ->orWhere(fn ($q) => $q->where('status', Call::STATUS_ONGOING)
                    ->where(fn ($q) => $q->whereNull('caller_seen_at')->orWhere('caller_seen_at', '<', $staleDeadline))
                    ->where(fn ($q) => $q->whereNull('callee_seen_at')->orWhere('callee_seen_at', '<', $staleDeadline))))
            ->limit(100)
            ->get();

        foreach ($calls as $call) {
            $this->finish($call, $call->isRinging() ? Call::REASON_MISSED : Call::REASON_COMPLETED, null);
        }

        return $calls->count();
    }

    /**
     * End a call once: record the outcome, add the call-history message and tell both sides.
     */
    public function finish(Call $call, string $reason, ?User $by): Call
    {
        $result = DB::transaction(function () use ($call, $reason, $by) {
            /** @var Call $locked */
            $locked = Call::query()->lockForUpdate()->findOrFail($call->getKey());

            if (! $locked->isActive()) {
                return [$locked, null, false];
            }

            $wasAnswered = $locked->answered_at !== null;
            $endedAt = now();

            // A call whose devices vanished lasted until they were last heard from.
            if ($by === null && $wasAnswered) {
                $lastSeen = collect([$locked->caller_seen_at, $locked->callee_seen_at])->filter()->max();
                $endedAt = $lastSeen && $lastSeen->lt($endedAt) ? $lastSeen : $endedAt;
            }

            $locked->forceFill([
                'status' => Call::STATUS_ENDED,
                'end_reason' => $reason,
                'ended_at' => $endedAt,
                'ended_by' => $by?->getKey(),
                'duration' => $wasAnswered ? max(0, (int) $locked->answered_at->diffInSeconds($endedAt, true)) : null,
            ])->save();

            $message = $this->recordHistory($locked);
            $locked->forceFill(['message_id' => $message->getKey()])->save();
            $locked->signals()->delete();

            return [$locked, $message, ! $wasAnswered];
        });

        [$ended, $message, $wasRinging] = $result;

        if (! $message) {
            return $ended; // already ended by the other side or another device
        }

        $ended->load(['caller', 'callee']);
        broadcast(new CallUpdated($ended));
        broadcast(new MessageSent($message->load('replyTo')));

        // Someone rung into a group call did not answer (K6).
        if ($wasRinging && $ended->call_room_id) {
            app(CallRoomService::class)->inviteEnded($ended);
        }

        // Stop the ringing screen on the callee's phones.
        if ($wasRinging && $this->push->enabled()) {
            SendCallPush::dispatchAfterResponse($ended->getKey(), SendCallPush::KIND_STATE);
        }

        return $ended;
    }

    private function wasConnected(Call $call): bool
    {
        return $call->answered_at !== null && $call->answered_at->diffInSeconds(now(), true) >= 5;
    }

    /**
     * Call-history bubble: sent by the caller; unread for the callee only when missed.
     */
    private function recordHistory(Call $call): Message
    {
        $now = now();
        $missed = in_array($call->end_reason, Call::MISSED_REASONS, true);

        $message = new Message;
        $message->forceFill([
            'conversation_id' => $call->conversation_id,
            'sender_id' => $call->caller_id,
            'receiver_id' => $call->callee_id,
            'message_type' => Message::TYPE_CALL,
            'message' => null,
            'attachment_meta' => [
                'call_id' => $call->getKey(),
                'call_type' => $call->type,
                'reason' => $call->end_reason,
                'duration' => $call->duration,
            ],
            'sent_at' => $now,
            'delivered_at' => $now,
            'seen_at' => $missed ? null : $now,
        ])->save();

        Conversation::query()->whereKey($call->conversation_id)->update(['last_message_id' => $message->getKey()]);

        return $message;
    }
}
