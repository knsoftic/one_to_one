<?php

namespace App\Http\Controllers;

use App\Events\ChatListsUpdated;
use App\Models\ChatList;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * C6 — a person's own chat lists ("Family", "Work"…), used as filters in the chat list.
 */
class ChatListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->chatLists()->with('conversations:conversations.id')->orderBy('position')->orderBy('id')->get()
                ->map(fn (ChatList $list) => $list->toPayload())
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->chatLists()->count() >= ChatList::MAX_PER_USER, 422, 'You can have up to '.ChatList::MAX_PER_USER.' lists.');

        $validated = $this->validateList($request);

        $list = DB::transaction(function () use ($user, $validated) {
            $list = $user->chatLists()->create([
                'name' => $validated['name'],
                'color' => $validated['color'] ?? null,
                'position' => (int) $user->chatLists()->max('position') + 1,
            ]);
            $this->syncChats($list, $validated['conversation_ids'] ?? []);

            return $list;
        });

        return $this->respond($request, $list, 201);
    }

    public function update(Request $request, ChatList $chatList): JsonResponse
    {
        $this->authorizeOwner($request, $chatList);
        $validated = $this->validateList($request, $chatList, partial: true);

        DB::transaction(function () use ($chatList, $validated) {
            if (isset($validated['name'])) {
                $chatList->update(['name' => $validated['name']]);
            }
            if (array_key_exists('color', $validated)) {
                $chatList->update(['color' => $validated['color']]);
            }
            if (array_key_exists('conversation_ids', $validated)) {
                $this->syncChats($chatList, $validated['conversation_ids'] ?? []);
            }
        });

        return $this->respond($request, $chatList);
    }

    public function destroy(Request $request, ChatList $chatList): JsonResponse
    {
        $this->authorizeOwner($request, $chatList);
        $chatList->delete();
        broadcast(new ChatListsUpdated($request->user()->getKey()))->toOthers();

        return response()->json(['id' => $chatList->id]);
    }

    private function validateList(Request $request, ?ChatList $list = null, bool $partial = false): array
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => [
                $partial ? 'sometimes' : 'required', 'string', 'max:'.ChatList::MAX_NAME,
                Rule::unique('chat_lists', 'name')->where('user_id', $user->getKey())->ignore($list?->id),
            ],
            'color' => ['sometimes', 'nullable', Rule::in(ChatList::COLORS)],
            'conversation_ids' => ['sometimes', 'array', 'max:500'],
            'conversation_ids.*' => ['integer', 'distinct'],
        ], ['name.unique' => 'You already have a list with this name.']);

        if (isset($validated['name'])) {
            $validated['name'] = trim(preg_replace('/\s+/u', ' ', $validated['name']) ?? '');
            abort_if($validated['name'] === '', 422, 'Give the list a name.');
        }

        if (! empty($validated['conversation_ids'])) {
            // Only the person's own chats.
            $validated['conversation_ids'] = Conversation::query()
                ->forUser($user)
                ->whereKey($validated['conversation_ids'])
                ->pluck('id')
                ->all();
        }

        return $validated;
    }

    private function syncChats(ChatList $list, array $ids): void
    {
        $list->conversations()->sync(collect($ids)->mapWithKeys(fn ($id) => [(int) $id => ['created_at' => now()]])->all());
    }

    private function authorizeOwner(Request $request, ChatList $list): void
    {
        abort_unless((int) $list->user_id === (int) $request->user()->getKey(), 404);
    }

    private function respond(Request $request, ChatList $list, int $status = 200): JsonResponse
    {
        broadcast(new ChatListsUpdated($request->user()->getKey()))->toOthers();

        return response()->json($list->load('conversations:conversations.id')->toPayload(), $status);
    }
}
