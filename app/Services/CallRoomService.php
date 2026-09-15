<?php

namespace App\Services;

use App\Events\CallRoomUpdated;
use App\Events\CallSignalSent;
use App\Events\CallStarted;
use App\Http\Resources\UserResource;
use App\Jobs\SendCallPush;
use App\Models\Call;
use App\Models\CallLink;
use App\Models\CallRoom;
use App\Models\CallRoomParticipant;
use App\Models\CallSignal;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * K6 — Group calls with up to 4 people.
 *
 * Every person added is rung with a normal call (so phones ring, "Decline"
 * works and missed calls are recorded as usual). Once they answer they join
 * the room and connect directly to everyone else in it; for each pair, the
 * person who joined later sends the WebRTC offer.
 */
class CallRoomService
{
    public function __construct(
        private readonly CallService $calls,
        private readonly ConversationService $conversations,
        private readonly PushService $push,
    ) {}

    public function maxParticipants(): int
    {
        return max(3, (int) config('chat.calls.max_group_participants', 4));
    }

    /**
     * The room of an ongoing one-to-one call; both people are in it already.
     *
     * @throws HttpException 409 when the call is not in progress
     */
    public function roomForCall(Call $call): CallRoom
    {
        $room = DB::transaction(function () use ($call) {
            /** @var Call $locked */
            $locked = Call::query()->lockForUpdate()->findOrFail($call->getKey());

            if ($locked->call_room_id) {
                return [CallRoom::query()->findOrFail($locked->call_room_id), false];
            }

            if (! $locked->isOngoing()) {
                throw new HttpException(409, 'The call is not in progress.');
            }

            $room = CallRoom::create([
                'host_id' => $locked->caller_id,
                'type' => $locked->type,
                'status' => CallRoom::STATUS_ACTIVE,
                'max_participants' => $this->maxParticipants(),
            ]);

            $now = now();
            foreach ([[$locked->caller_id, $locked->caller_client], [$locked->callee_id, $locked->callee_client]] as $seq => [$userId, $clientId]) {
                $room->participants()->create([
                    'user_id' => $userId,
                    'call_id' => $locked->getKey(),
                    'status' => CallRoomParticipant::STATUS_JOINED,
                    'client_id' => $clientId,
                    'join_seq' => $seq + 1,
                    'joined_at' => $locked->answered_at ?? $now,
                    'last_seen_at' => $now,
                ]);
            }

            $locked->forceFill(['call_room_id' => $room->getKey()])->save();

            return [$room, true];
        });

        [$room, $created] = $room;
        if ($created) {
            broadcast(new CallRoomUpdated($room));
        }

        return $room;
    }

    /**
     * Start a group call from scratch: the host joins on $clientId and everyone else is rung.
     *
     * @param  list<int>  $userIds
     */
    public function start(User $host, array $userIds, string $type, string $clientId): CallRoom
    {
        $this->calls->expireStale();

        if ($this->isBusy($host)) {
            throw new HttpException(409, 'You are already in a call.');
        }

        $room = DB::transaction(function () use ($host, $type, $clientId) {
            $room = CallRoom::create([
                'host_id' => $host->getKey(),
                'type' => $type,
                'status' => CallRoom::STATUS_ACTIVE,
                'max_participants' => $this->maxParticipants(),
            ]);

            $room->participants()->create([
                'user_id' => $host->getKey(),
                'status' => CallRoomParticipant::STATUS_JOINED,
                'client_id' => $clientId,
                'join_seq' => 1,
                'joined_at' => now(),
                'last_seen_at' => now(),
            ]);

            return $room;
        });

        $invited = 0;
        foreach (array_unique($userIds) as $userId) {
            $user = User::query()->find($userId);
            if (! $user || $user->is($host)) {
                continue;
            }
            try {
                $this->invite($room, $host, $user);
                $invited++;
            } catch (HttpException) {
                // Busy, blocked or not available: the others are still rung.
            }
        }

        if ($invited === 0) {
            $this->end($room);
            throw new HttpException(422, 'Nobody could be added to the call.');
        }

        return $room->fresh();
    }

    /**
     * Join the call of a call link (K7). The first person to open it starts the call and
     * rings the link's owner; later people join the same call while it lasts.
     */
    public function joinLink(CallLink $link, User $user, string $clientId): CallRoom
    {
        $owner = $link->user;

        if (! $owner?->isActive()) {
            throw new HttpException(410, 'This call link is no longer valid.');
        }

        if (! $user->is($owner) && ($user->hasBlocked($owner) || $user->isBlockedBy($owner))) {
            throw new HttpException(403, "You can't join this call.");
        }

        $this->calls->expireStale();

        $room = DB::transaction(function () use ($link, $user, $clientId, $owner) {
            $room = CallRoom::query()->active()->where('link_token', $link->token)->lockForUpdate()->latest('id')->first();
            $participant = $room?->participantFor($user);

            if ($participant?->isJoined() && $participant->client_id === $clientId) {
                return $room;
            }

            if (! $participant?->isJoined() && $this->isBusy($user)) {
                throw new HttpException(409, 'You are already in a call.');
            }

            if (! $room) {
                $room = CallRoom::create([
                    'host_id' => $owner->getKey(),
                    'type' => $link->type,
                    'status' => CallRoom::STATUS_ACTIVE,
                    'max_participants' => $this->maxParticipants(),
                    'link_token' => $link->token,
                ]);
            } elseif (! $participant || ! in_array($participant->status, CallRoomParticipant::TAKING_PLACE, true)) {
                if ($room->participants()->whereIn('status', CallRoomParticipant::TAKING_PLACE)->count() >= $room->max_participants) {
                    throw new HttpException(422, 'The call is full.');
                }
            }

            $room->participants()->updateOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'status' => CallRoomParticipant::STATUS_JOINED,
                    'client_id' => $clientId,
                    'join_seq' => (int) $room->participants()->max('join_seq') + 1,
                    'joined_at' => now(),
                    'left_at' => null,
                    'last_seen_at' => now(),
                ],
            );

            $link->forceFill(['last_used_at' => now()])->save();

            return $room;
        });

        broadcast(new CallRoomUpdated($room));

        // Someone opened the link: ring its owner (if they are not already there).
        $ownerParticipant = $room->participantFor($owner);
        if (! $user->is($owner) && ! in_array($ownerParticipant?->status, CallRoomParticipant::TAKING_PLACE, true)) {
            try {
                $this->invite($room, $user, $owner);
            } catch (HttpException) {
                // The owner is busy or unavailable: the call waits for others with the link.
            }
        }

        return $room->fresh();
    }

    /**
     * Ring $invitee into the room on behalf of $by.
     */
    public function invite(CallRoom $room, User $by, User $invitee): Call
    {
        [$call, $room] = DB::transaction(function () use ($room, $by, $invitee) {
            /** @var CallRoom $room */
            $room = CallRoom::query()->lockForUpdate()->findOrFail($room->getKey());

            if (! $room->isActive()) {
                throw new HttpException(409, 'The call has ended.');
            }

            $inviter = $room->participantFor($by);
            if (! $inviter?->isJoined()) {
                throw new HttpException(403, 'Only people in the call can add others.');
            }

            $existing = $room->participantFor($invitee);
            if ($existing && in_array($existing->status, CallRoomParticipant::TAKING_PLACE, true)) {
                throw new HttpException(422, "{$invitee->name} is already in the call.");
            }

            if ($room->participants()->whereIn('status', CallRoomParticipant::TAKING_PLACE)->count() >= $room->max_participants) {
                throw new HttpException(422, "A group call can have up to {$room->max_participants} people.");
            }

            if (! $invitee->isActive() || $by->hasBlocked($invitee) || $by->isBlockedBy($invitee)) {
                throw new HttpException(403, "You can't add {$invitee->name} to the call.");
            }

            if ($this->isBusy($invitee)) {
                throw new HttpException(409, "{$invitee->name} is on another call.");
            }

            $conversation = $this->conversations->findOrCreate($by, $invitee);

            $call = Call::create([
                'conversation_id' => $conversation->getKey(),
                'call_room_id' => $room->getKey(),
                'caller_id' => $by->getKey(),
                'callee_id' => $invitee->getKey(),
                'type' => $room->type,
                'status' => Call::STATUS_RINGING,
                'caller_client' => $inviter->client_id,
                'caller_seen_at' => now(),
            ]);

            $room->participants()->updateOrCreate(
                ['user_id' => $invitee->getKey()],
                [
                    'call_id' => $call->getKey(),
                    'invited_by' => $by->getKey(),
                    'status' => CallRoomParticipant::STATUS_RINGING,
                    'client_id' => null,
                    'join_seq' => null,
                    'joined_at' => null,
                    'left_at' => null,
                ],
            );

            return [$call, $room];
        });

        $call->load(['caller', 'callee']);
        broadcast(new CallStarted($call));
        broadcast(new CallRoomUpdated($room));

        if ($this->push->reachable($call->callee_id)) {
            SendCallPush::dispatchAfterResponse($call->getKey(), SendCallPush::KIND_INCOMING);
        }

        return $call;
    }

    /**
     * $user takes part from device $clientId (answered an invite).
     */
    public function join(CallRoom $room, User $user, string $clientId): CallRoomParticipant
    {
        [$participant, $room] = DB::transaction(function () use ($room, $user, $clientId) {
            /** @var CallRoom $room */
            $room = CallRoom::query()->lockForUpdate()->findOrFail($room->getKey());

            if (! $room->isActive()) {
                throw new HttpException(409, 'The call has ended.');
            }

            $participant = $room->participantFor($user);
            if (! $participant) {
                throw new HttpException(403, 'You were not added to this call.');
            }

            if (! $participant->isJoined()
                && $room->participants()->joined()->count() >= $room->max_participants) {
                throw new HttpException(422, 'The call is full.');
            }

            $participant->forceFill([
                'status' => CallRoomParticipant::STATUS_JOINED,
                'client_id' => $clientId,
                'join_seq' => (int) $room->participants()->max('join_seq') + 1,
                'joined_at' => now(),
                'left_at' => null,
                'last_seen_at' => now(),
            ])->save();

            return [$participant, $room];
        });

        broadcast(new CallRoomUpdated($room));

        return $participant;
    }

    /**
     * $user hangs up: their calls in the room end; the room ends when fewer than two remain.
     */
    public function leave(CallRoom $room, User $user): void
    {
        $participant = $room->participantFor($user);

        if ($participant?->isJoined()) {
            $participant->forceFill(['status' => CallRoomParticipant::STATUS_LEFT, 'left_at' => now()])->save();
        }

        foreach ($room->calls()->active()->involving($user)->get() as $call) {
            $this->calls->finish($call, $this->endReasonFor($call, $user), $user);
        }

        $room = $room->fresh();
        if ($room->isActive() && ! $this->settle($room)) {
            broadcast(new CallRoomUpdated($room));
        }
    }

    /**
     * An invite call ended without being answered (declined, missed, cancelled, busy).
     */
    public function inviteEnded(Call $call): void
    {
        $room = $call->room;
        if (! $room) {
            return;
        }

        $room->participants()
            ->where('call_id', $call->getKey())
            ->where('status', CallRoomParticipant::STATUS_RINGING)
            ->update(['status' => $call->end_reason === Call::REASON_DECLINED ? CallRoomParticipant::STATUS_DECLINED : CallRoomParticipant::STATUS_MISSED]);

        $room = $room->fresh();
        if ($room->isActive() && ! $this->settle($room)) {
            broadcast(new CallRoomUpdated($room));
        }
    }

    /**
     * End the room when nobody is left to talk to.
     *
     * @return bool whether the room ended (and the update was broadcast)
     */
    public function settle(CallRoom $room): bool
    {
        if (! $room->isActive()) {
            return false;
        }

        $joined = $room->participants()->joined()->count();
        $ringing = $room->participants()->where('status', CallRoomParticipant::STATUS_RINGING)->count();

        // A call opened from a link keeps waiting for people with the link while someone is in it.
        if ($joined >= 2 || ($joined === 1 && ($ringing > 0 || $room->link_token !== null))) {
            return false;
        }

        $this->end($room);

        return true;
    }

    public function end(CallRoom $room): void
    {
        $ended = CallRoom::query()->whereKey($room->getKey())->where('status', CallRoom::STATUS_ACTIVE)
            ->update(['status' => CallRoom::STATUS_ENDED, 'ended_at' => now()]);

        if (! $ended) {
            return;
        }

        $room->refresh();
        $room->participants()->joined()->update(['status' => CallRoomParticipant::STATUS_LEFT, 'left_at' => now()]);

        foreach ($room->calls()->active()->get() as $call) {
            $this->calls->finish($call, $call->isRinging() ? Call::REASON_CANCELLED : Call::REASON_COMPLETED, null);
        }

        CallSignal::query()->where('call_room_id', $room->getKey())->delete();
        broadcast(new CallRoomUpdated($room));
    }

    /**
     * WebRTC message from $sender's device to one device of someone else in the room.
     */
    public function signal(CallRoom $room, User $sender, string $fromClient, int $toUserId, ?string $toClient, string $type, string $payload): CallSignal
    {
        if (! $room->isActive()) {
            throw new HttpException(409, 'The call has ended.');
        }

        if (! $room->participantFor($sender)?->isJoined() || ! $room->participantFor($toUserId)?->isJoined()) {
            throw new HttpException(409, 'That person is not in the call.');
        }

        $signal = CallSignal::create([
            'call_room_id' => $room->getKey(),
            'sender_id' => $sender->getKey(),
            'recipient_id' => $toUserId,
            'from_client' => $fromClient,
            'to_client' => $toClient,
            'type' => $type,
            'payload' => $payload,
        ]);

        broadcast(new CallSignalSent($signal));

        return $signal;
    }

    /**
     * @return Collection<int, CallSignal>
     */
    public function signalsFor(CallRoom $room, User $user, string $clientId, int $afterId = 0): Collection
    {
        return CallSignal::query()
            ->where('call_room_id', $room->getKey())
            ->where('recipient_id', $user->getKey())
            ->where('id', '>', $afterId)
            ->where(fn ($q) => $q->whereNull('to_client')->orWhere('to_client', $clientId))
            ->orderBy('id')
            ->limit(200)
            ->get();
    }

    /**
     * The device taking part is still there; people whose devices vanished are removed.
     */
    public function heartbeat(CallRoom $room, User $user): void
    {
        $room->participants()->where('user_id', $user->getKey())->joined()->update(['last_seen_at' => now()]);

        foreach ($room->calls()->active()->involving($user)->get() as $call) {
            $this->calls->touch($call, $user);
        }

        $this->expireStale($room);
    }

    public function expireStale(CallRoom $room): void
    {
        if (! $room->isActive()) {
            return;
        }

        $deadline = now()->subSeconds((int) config('chat.calls.stale_after_seconds', 90));
        $stale = $room->participants()->joined()->where('last_seen_at', '<', $deadline)->with('user')->get();

        foreach ($stale as $participant) {
            if ($participant->user) {
                $this->leave($room, $participant->user);
            }
        }
    }

    /** Close the places of people whose devices stopped reporting in, in every group call (scheduled). */
    public function expireAllStale(): int
    {
        $deadline = now()->subSeconds((int) config('chat.calls.stale_after_seconds', 90));
        $rooms = CallRoom::query()->active()
            ->whereHas('participants', fn ($q) => $q->joined()->where('last_seen_at', '<', $deadline))
            ->limit(100)->get();

        $rooms->each(fn (CallRoom $room) => $this->expireStale($room));

        return $rooms->count();
    }

    /** In a call already (ringing or talking one-to-one, or in a group call). */
    public function isBusy(User|int $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return Call::query()->active()->involving($id)->exists()
            || CallRoomParticipant::query()->joined()->where('user_id', $id)
                // A device that stopped reporting in isn't in the call any more.
                ->where('last_seen_at', '>=', now()->subSeconds((int) config('chat.calls.stale_after_seconds', 90)))
                ->whereHas('room', fn ($q) => $q->active())
                ->exists();
    }

    /**
     * Viewer-neutral room state for the call screen.
     *
     * @return array<string, mixed>
     */
    public function payload(CallRoom $room, ?Request $request = null): array
    {
        $request ??= Request::create('/');
        $room->loadMissing(['participants.user', 'participants.call:id,conversation_id']);

        return [
            'id' => $room->id,
            'type' => $room->type,
            'status' => $room->status,
            'host_id' => $room->host_id,
            'max_participants' => $room->max_participants,
            'from_link' => $room->link_token !== null,
            'participants' => $room->participants
                ->sortBy(fn (CallRoomParticipant $p) => [$p->join_seq ?? PHP_INT_MAX, $p->id])
                ->map(fn (CallRoomParticipant $p) => [
                    'user_id' => $p->user_id,
                    'user' => $p->user ? (new UserResource($p->user))->resolve($request) : null,
                    'status' => $p->status,
                    'client_id' => $p->client_id,
                    'join_seq' => $p->join_seq,
                    'call_id' => $p->call_id,
                    // The chat of the call that rang this person (opened from the phone's call notification).
                    'conversation_id' => $p->call?->conversation_id,
                    'invited_by' => $p->invited_by,
                    'joined_at' => $p->joined_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'ended_at' => $room->ended_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    private function endReasonFor(Call $call, User $user): string
    {
        if ($call->isOngoing()) {
            return Call::REASON_COMPLETED;
        }

        return $call->isCaller($user) ? Call::REASON_CANCELLED : Call::REASON_DECLINED;
    }
}
