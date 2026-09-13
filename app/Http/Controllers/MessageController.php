<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\DeleteMessageRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MessageController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    /**
     * Paginated history (latest page first; pass ?before={id} for older messages).
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $validated = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->messages->history(
            $conversation,
            $request->user(),
            isset($validated['before']) ? (int) $validated['before'] : null,
            isset($validated['limit']) ? (int) $validated['limit'] : null,
        );

        return response()->json([
            'data' => MessageResource::collection($page['messages'])->resolve($request),
            'has_more' => $page['has_more'],
        ]);
    }

    /**
     * Send a text, image, document or voice message.
     */
    public function store(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $type = $request->attachmentType();
        $data = $request->safe()->only(['message', 'reply_to_id', 'duration']);

        $message = $type === Message::TYPE_TEXT
            ? $this->messages->sendText($request->user(), $conversation, $data)
            : $this->messages->sendAttachment(
                $request->user(),
                $conversation,
                $request->file($type === Message::TYPE_VOICE ? 'voice' : 'attachment'),
                $type,
                $data,
            );

        return response()->json(
            (new MessageResource($message))->resolve($request) + ['client_id' => $request->input('client_id')],
            201
        );
    }

    /**
     * Edit a text message (shows "Edited").
     */
    public function update(UpdateMessageRequest $request, Message $message): MessageResource
    {
        return new MessageResource($this->messages->edit($message, $request->validated('message')));
    }

    /**
     * Delete for me, or delete for everyone.
     */
    public function destroy(DeleteMessageRequest $request, Message $message): JsonResponse
    {
        if ($request->forEveryone()) {
            $message = $this->messages->deleteForEveryone($message);

            return response()->json([
                'scope' => DeleteMessageRequest::SCOPE_EVERYONE,
                'message' => (new MessageResource($message))->resolve($request),
            ]);
        }

        $this->messages->deleteForMe($message, $request->user());

        return response()->json([
            'scope' => DeleteMessageRequest::SCOPE_ME,
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
        ]);
    }
}
