<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Services\ChannelService;
use App\Services\ConversationService;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * G11 — Channels.
 */
class ChannelController extends Controller
{
    public function __construct(
        private readonly ChannelService $channels,
        private readonly ConversationService $conversations,
    ) {}

    /** Find channels (by name or description). */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $user = $request->user();

        return response()->json([
            'data' => $this->channels->directory($validated['q'] ?? null)
                ->map(fn (Conversation $channel) => ['id' => $channel->id] + $this->channels->payload($channel, $user))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.GroupService::MAX_NAME],
            'description' => ['nullable', 'string', 'max:'.GroupService::MAX_DESCRIPTION],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('chat.uploads.avatar.max_kb', 5120)],
        ], ['name.required' => 'Give the channel a name.']);

        $channel = $this->channels->create($request->user(), $validated['name'], $validated['description'] ?? null, $request->file('avatar'));

        return $this->respond($request, $channel, 201);
    }

    /** A channel and its latest updates, for anyone (also before following). */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        return response()->json($this->channels->preview($conversation, $request->user()));
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:'.GroupService::MAX_NAME],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.GroupService::MAX_DESCRIPTION],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('chat.uploads.avatar.max_kb', 5120)],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $this->channels->update(
            $conversation,
            $request->user(),
            array_intersect_key($validated, array_flip(['name', 'description'])),
            $request->file('avatar'),
            $request->boolean('remove_avatar'),
        );

        return $this->respond($request, $conversation);
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->channels->delete($conversation, $request->user());

        return response()->json(['id' => $conversation->id, 'deleted' => true]);
    }

    public function follow(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->respond($request, $this->channels->follow($conversation, $request->user()));
    }

    public function unfollow(Request $request, Conversation $conversation): JsonResponse
    {
        $this->channels->unfollow($conversation, $request->user());

        return response()->json(['id' => $conversation->id, 'following' => false]);
    }

    /** The page a channel link opens (the chat app shows the channel). */
    public function linkPage(Request $request, string $token): View
    {
        $channel = $this->channels->findByInvite($token);

        return view('chat.index', [
            'user' => $request->user(),
            'initialConversationId' => null,
            'channelInvite' => $channel ? ['valid' => true, 'id' => $channel->id] : ['valid' => false],
        ]);
    }

    private function respond(Request $request, Conversation $channel, int $status = 200): JsonResponse
    {
        $channel->unsetRelation('members');

        return (new ConversationResource($this->conversations->loadForUser($channel->fresh(), $request->user())))
            ->response()
            ->setStatusCode($status);
    }
}
