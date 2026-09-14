<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\ClientIdRule;
use App\Http\Resources\CallResource;
use App\Models\Call;
use App\Models\CallRoom;
use App\Models\CallSignal;
use App\Models\User;
use App\Services\CallRoomService;
use App\Services\IceServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * K6 — Group calls: add people, take part, leave and WebRTC signaling between them.
 */
class CallRoomController extends Controller
{
    public function __construct(
        private readonly CallRoomService $rooms,
        private readonly IceServerService $iceServers,
    ) {}

    /** "Add person" during a one-to-one call: it becomes a group call. */
    public function addToCall(Request $request, Call $call): JsonResponse
    {
        Gate::authorize('view', $call);
        abort_unless(config('chat.calls.enabled', true), 403, 'Calls are not available.');
        $invitee = $this->invitee($request);

        $room = $this->rooms->roomForCall($call);
        $invite = $this->rooms->invite($room, $request->user(), $invitee);

        return $this->roomResponse($request, $room->fresh(), 201, $invite);
    }

    /** Start a group call with up to three other people. */
    public function store(Request $request): JsonResponse
    {
        abort_unless(config('chat.calls.enabled', true), 403, 'Calls are not available.');

        $max = $this->rooms->maxParticipants() - 1;
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', "max:{$max}"],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('status', User::STATUS_ACTIVE)],
            'type' => ['required', Rule::in(Call::TYPES)],
            'client_id' => ClientIdRule::rules(),
        ], [
            'user_ids.max' => "A group call can have up to {$this->rooms->maxParticipants()} people.",
        ]);

        $room = $this->rooms->start($request->user(), $validated['user_ids'], $validated['type'], $validated['client_id']);

        return $this->roomResponse($request, $room, 201);
    }

    public function show(Request $request, CallRoom $room): JsonResponse
    {
        Gate::authorize('view', $room);
        $this->rooms->expireStale($room);

        return $this->roomResponse($request, $room->fresh());
    }

    public function invite(Request $request, CallRoom $room): JsonResponse
    {
        Gate::authorize('view', $room);
        $invite = $this->rooms->invite($room, $request->user(), $this->invitee($request));

        return $this->roomResponse($request, $room->fresh(), 201, $invite);
    }

    public function leave(Request $request, CallRoom $room): JsonResponse
    {
        Gate::authorize('view', $room);
        $this->rooms->leave($room, $request->user());

        return $this->roomResponse($request, $room->fresh());
    }

    public function heartbeat(Request $request, CallRoom $room): JsonResponse
    {
        Gate::authorize('view', $room);
        $this->rooms->heartbeat($room, $request->user());

        return response()->json(['room' => $this->rooms->payload($room->fresh(), $request)]);
    }

    public function storeSignal(Request $request, CallRoom $room): JsonResponse
    {
        Gate::authorize('view', $room);

        $validated = $request->validate([
            'client_id' => ClientIdRule::rules(),
            'to_user_id' => ['required', 'integer'],
            'to_client' => ClientIdRule::rules(required: false),
            'type' => ['required', Rule::in(CallSignal::TYPES)],
            'payload' => ['required', 'string', 'max:60000', 'json'],
        ]);

        $signal = $this->rooms->signal(
            $room,
            $request->user(),
            $validated['client_id'],
            (int) $validated['to_user_id'],
            $validated['to_client'] ?? null,
            $validated['type'],
            $validated['payload'],
        );

        return response()->json(['id' => $signal->id], 201);
    }

    public function signals(Request $request, CallRoom $room): JsonResponse
    {
        Gate::authorize('view', $room);

        $validated = $request->validate([
            'client_id' => ClientIdRule::rules(),
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $signals = $this->rooms->signalsFor($room, $request->user(), $validated['client_id'], (int) ($validated['after'] ?? 0));

        return response()->json([
            'status' => $room->status,
            'data' => $signals->map(fn (CallSignal $signal) => $signal->toPayload())->values(),
        ]);
    }

    private function invitee(Request $request): User
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('status', User::STATUS_ACTIVE)],
        ], ['user_id.exists' => 'This person is not available.']);

        abort_if((int) $validated['user_id'] === (int) $request->user()->getKey(), 422, 'You are already in the call.');

        return User::query()->findOrFail($validated['user_id']);
    }

    private function roomResponse(Request $request, CallRoom $room, int $status = 200, ?Call $invite = null): JsonResponse
    {
        return response()->json(array_filter([
            'room' => $this->rooms->payload($room, $request),
            'invite' => $invite ? (new CallResource($invite->loadMissing(['caller', 'callee'])))->resolve($request) : null,
            'ice_servers' => $room->isActive() ? $this->iceServers->for($request->user()) : [],
        ], fn ($value) => $value !== null), $status);
    }
}
