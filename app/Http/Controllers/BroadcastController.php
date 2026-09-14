<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Services\BroadcastService;
use App\Services\ConversationService;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * G9 — Broadcast lists.
 */
class BroadcastController extends Controller
{
    public function __construct(
        private readonly BroadcastService $broadcasts,
        private readonly ConversationService $conversations,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:'.GroupService::MAX_NAME],
            'user_ids' => ['required', 'array', 'min:'.BroadcastService::MIN_RECIPIENTS, 'max:'.$this->broadcasts->maxRecipients()],
            'user_ids.*' => ['integer', 'distinct'],
        ], [
            'user_ids.min' => 'A broadcast list needs at least '.BroadcastService::MIN_RECIPIENTS.' people.',
        ]);

        $list = $this->broadcasts->create($request->user(), $validated['name'] ?? null, $validated['user_ids']);

        return $this->respond($request, $list, 201);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:'.GroupService::MAX_NAME],
            'user_ids' => ['sometimes', 'array', 'min:'.BroadcastService::MIN_RECIPIENTS, 'max:'.$this->broadcasts->maxRecipients()],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        $this->broadcasts->update(
            $conversation,
            $request->user(),
            array_key_exists('name', $validated),
            $validated['name'] ?? null,
            $validated['user_ids'] ?? null,
        );

        return $this->respond($request, $conversation);
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->broadcasts->delete($conversation, $request->user());

        return response()->json(['id' => $conversation->id, 'deleted' => true]);
    }

    private function respond(Request $request, Conversation $list, int $status = 200): JsonResponse
    {
        return (new ConversationResource($this->conversations->loadForUser($list->fresh(), $request->user())))
            ->response()
            ->setStatusCode($status);
    }
}
