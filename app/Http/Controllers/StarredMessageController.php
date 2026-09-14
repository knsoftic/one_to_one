<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Models\Message;
use App\Models\StarredMessage;
use App\Services\ChatLockService;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Starred messages: private bookmarks, listed across all chats.
 */
class StarredMessageController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct(private readonly ContactService $contacts) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();

        $stars = StarredMessage::query()
            ->where('user_id', $user->getKey())
            ->when($validated['before'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))
            ->whereHas('message', fn ($q) => $q->visibleTo($user)->where('deleted_for_everyone', false)
                // Starred messages of locked chats (C9) stay hidden until the code is entered.
                ->whereNotIn('conversation_id', app(ChatLockService::class)->hiddenIds($user)))
            ->with(['message' => fn ($q) => $q->with([...Message::DISPLAY_RELATIONS, 'sender', 'receiver', 'conversation'])->withViewerState($user)])
            ->orderByDesc('id')
            ->limit(self::PER_PAGE + 1)
            ->get();

        $hasMore = $stars->count() > self::PER_PAGE;
        $stars = $stars->take(self::PER_PAGE);

        $peerIds = $stars->map(fn (StarredMessage $star) => $star->message->isSentBy($user) && ! $star->message->isGroupMessage() ? $star->message->receiver_id : $star->message->sender_id);
        $savedNames = $this->contacts->savedNames($user, $peerIds->unique()->values()->all());

        return response()->json([
            'data' => $stars->map(function (StarredMessage $star) use ($request, $user, $savedNames) {
                $message = $star->message;
                // Group messages (Phase 4): the person who wrote it, and the group.
                $peer = $message->isSentBy($user) && ! $message->isGroupMessage() ? $message->receiver : $message->sender;
                $peerData = (new UserResource($peer))->resolve($request);
                $peerData['saved_name'] = $savedNames[$peer->id] ?? null;

                return [
                    'star_id' => $star->id,
                    'starred_at' => $star->created_at?->toIso8601String(),
                    'peer' => $peerData,
                    'group' => $message->isGroupMessage() ? ['id' => $message->conversation_id, 'name' => $message->conversation?->name] : null,
                    'message' => (new MessageResource($message))->resolve($request),
                ];
            })->values(),
            'has_more' => $hasMore,
        ]);
    }

    public function store(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message);

        StarredMessage::query()->firstOrCreate(['user_id' => $request->user()->getKey(), 'message_id' => $message->getKey()]);

        return response()->json(['id' => $message->id, 'is_starred' => true]);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message);

        StarredMessage::query()->where('user_id', $request->user()->getKey())->where('message_id', $message->getKey())->delete();

        return response()->json(['id' => $message->id, 'is_starred' => false]);
    }
}
