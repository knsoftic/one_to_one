<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\ClientIdRule;
use App\Http\Resources\UserResource;
use App\Models\Call;
use App\Models\CallLink;
use App\Services\CallRoomService;
use App\Services\IceServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * K7 — Call links: create, share, delete and join.
 */
class CallLinkController extends Controller
{
    public function __construct(
        private readonly CallRoomService $rooms,
        private readonly IceServerService $iceServers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $links = $request->user()->hasMany(CallLink::class)->usable()->latest('id')->get();

        return response()->json(['data' => $links->map(fn (CallLink $link) => $this->payload($link, $request))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(config('chat.calls.enabled', true), 403, 'Calls are not available.');
        $validated = $request->validate(['type' => ['required', Rule::in(Call::TYPES)]]);
        $user = $request->user();

        abort_if(
            CallLink::query()->where('user_id', $user->getKey())->usable()->count() >= CallLink::MAX_ACTIVE_PER_USER,
            422,
            'You can have up to '.CallLink::MAX_ACTIVE_PER_USER.' call links. Delete one first.',
        );

        $link = CallLink::create([
            'user_id' => $user->getKey(),
            'token' => Str::random(24),
            'type' => $validated['type'],
        ]);

        return response()->json($this->payload($link, $request), 201);
    }

    public function destroy(Request $request, CallLink $callLink): JsonResponse
    {
        abort_unless((int) $callLink->user_id === (int) $request->user()->getKey(), 404);
        $callLink->forceFill(['revoked_at' => now()])->save();

        return response()->json(['token' => $callLink->token, 'deleted' => true]);
    }

    /** The link opens the chat app, which offers to join the call. */
    public function show(Request $request, string $token): View
    {
        $link = CallLink::query()->where('token', $token)->with('user')->first();

        return view('chat.index', [
            'user' => $request->user(),
            'initialConversationId' => null,
            'callLink' => $link && ! $link->revoked_at && $link->user?->isActive()
                ? $this->payload($link, $request)
                : ['token' => $token, 'valid' => false],
        ]);
    }

    public function join(Request $request, string $token): JsonResponse
    {
        abort_unless(config('chat.calls.enabled', true), 403, 'Calls are not available.');
        $validated = $request->validate(['client_id' => ClientIdRule::rules()]);

        $link = CallLink::query()->where('token', $token)->first();
        abort_if($link === null || $link->revoked_at !== null, 410, 'This call link is no longer valid.');

        $room = $this->rooms->joinLink($link, $request->user(), $validated['client_id']);

        return response()->json([
            'room' => $this->rooms->payload($room, $request),
            'ice_servers' => $this->iceServers->for($request->user()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CallLink $link, Request $request): array
    {
        $link->loadMissing('user');

        return [
            'token' => $link->token,
            'valid' => true,
            'type' => $link->type,
            'url' => $link->url(),
            'owner' => $link->user ? (new UserResource($link->user))->resolve($request) : null,
            'is_mine' => (int) $link->user_id === (int) $request->user()?->getKey(),
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
