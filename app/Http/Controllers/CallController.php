<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\ClientIdRule;
use App\Http\Requests\Chat\StartCallRequest;
use App\Http\Resources\CallResource;
use App\Models\Call;
use App\Models\CallSignal;
use App\Models\Conversation;
use App\Services\CallService;
use App\Services\IceServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Voice & video calls: start, answer, decline, hang up and WebRTC signaling.
 */
class CallController extends Controller
{
    public function __construct(
        private readonly CallService $calls,
        private readonly IceServerService $iceServers,
    ) {}

    public function store(StartCallRequest $request, Conversation $conversation): JsonResponse
    {
        $call = $this->calls->start(
            $request->user(),
            $conversation,
            $request->validated('type'),
            $request->validated('client_id'),
        );

        return $this->callResponse($request, $call, 201);
    }

    /**
     * Ringing or ongoing calls of the current user (restores the call screen).
     */
    public function active(Request $request): JsonResponse
    {
        return response()->json([
            'data' => CallResource::collection($this->calls->activeFor($request->user()))->resolve($request),
            'ice_servers' => $this->iceServers->for($request->user()),
        ]);
    }

    public function show(Request $request, Call $call): JsonResponse
    {
        Gate::authorize('view', $call);

        return $this->callResponse($request, $call);
    }

    public function ringing(Request $request, Call $call): JsonResponse
    {
        Gate::authorize('view', $call);

        return $this->callResponse($request, $this->calls->markRinging($call, $request->user()));
    }

    public function accept(Request $request, Call $call): JsonResponse
    {
        Gate::authorize('view', $call);
        $validated = $request->validate(['client_id' => ClientIdRule::rules()]);

        return $this->callResponse($request, $this->calls->accept($call, $request->user(), $validated['client_id']));
    }

    public function decline(Request $request, Call $call): JsonResponse
    {
        Gate::authorize('view', $call);

        return $this->callResponse($request, $this->calls->decline($call, $request->user()));
    }

    public function end(Request $request, Call $call): JsonResponse
    {
        Gate::authorize('view', $call);
        $validated = $request->validate(['reason' => ['nullable', Rule::in(['hangup', 'no_answer', 'failed'])]]);

        return $this->callResponse($request, $this->calls->hangUp($call, $request->user(), $validated['reason'] ?? null));
    }

    public function heartbeat(Request $request, Call $call): JsonResponse
    {
        Gate::authorize('view', $call);
        $this->calls->touch($call, $request->user());

        return response()->json(['status' => $call->status, 'end_reason' => $call->end_reason]);
    }

    public function storeSignal(Request $request, Call $call): JsonResponse
    {
        Gate::authorize('view', $call);

        $validated = $request->validate([
            'client_id' => ClientIdRule::rules(),
            'to_client' => ClientIdRule::rules(required: false),
            'type' => ['required', Rule::in(CallSignal::TYPES)],
            'payload' => ['required', 'string', 'max:60000', 'json'],
        ]);

        $signal = $this->calls->signal(
            $call,
            $request->user(),
            $validated['client_id'],
            $validated['type'],
            $validated['payload'],
            $validated['to_client'] ?? null,
        );

        return response()->json(['id' => $signal->id], 201);
    }

    /**
     * Signals for this device (large session descriptions, or everything while
     * the WebSocket is unavailable).
     */
    public function signals(Request $request, Call $call): JsonResponse
    {
        Gate::authorize('view', $call);

        $validated = $request->validate([
            'client_id' => ClientIdRule::rules(),
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $signals = $this->calls->signalsFor($call, $request->user(), $validated['client_id'], (int) ($validated['after'] ?? 0));

        return response()->json([
            'status' => $call->status,
            'end_reason' => $call->end_reason,
            'data' => $signals->map(fn (CallSignal $signal) => $signal->toPayload())->values(),
        ]);
    }

    private function callResponse(Request $request, Call $call, int $status = 200): JsonResponse
    {
        $call->loadMissing(['caller', 'callee']);

        return response()->json([
            'call' => (new CallResource($call))->resolve($request),
            'ice_servers' => $call->isActive() ? $this->iceServers->for($request->user()) : [],
        ], $status);
    }
}
