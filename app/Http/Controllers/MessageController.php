<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\DeleteMessageRequest;
use App\Http\Requests\Chat\ForwardMessageRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Sticker;
use App\Services\LinkPreviewService;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

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
     * D1 — Media, links and docs of a chat (?kind=media|docs|links, ?before={id} for older).
     */
    public function gallery(Request $request, Conversation $conversation, LinkPreviewService $links): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $validated = $request->validate([
            'kind' => ['required', Rule::in(MessageService::GALLERY_KINDS)],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $page = $this->messages->gallery($conversation, $user, $validated['kind'], isset($validated['before']) ? (int) $validated['before'] : null);
        $items = MessageResource::collection($page['messages'])->resolve($request);

        if ($validated['kind'] === 'links') {
            foreach ($page['messages']->values() as $index => $message) {
                $items[$index]['links'] = $links->urls($message->message);
            }
        }

        return response()->json([
            'data' => $items,
            'has_more' => $page['has_more'],
            // Tab counts come with the first page.
            'counts' => isset($validated['before']) ? null : $this->messages->galleryCounts($conversation, $user),
        ]);
    }

    /**
     * Send a text, image, document or voice message.
     */
    public function store(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $type = $request->attachmentType();
        $data = $request->safe()->only(['message', 'reply_to_id', 'duration', 'link_preview', 'album_id', 'quality', 'view_once', 'mentions']);

        try {
            $message = match (true) {
                $request->filled('sticker_id') => $this->messages->sendSticker(
                    $request->user(), $conversation, Sticker::findOrFail((int) $request->input('sticker_id')), $data,
                ),
                $request->filled('poll') => $this->messages->sendPoll($request->user(), $conversation, (array) $request->validated('poll'), $data),
                $request->filled('contact') => $this->messages->sendContact($request->user(), $conversation, (array) $request->validated('contact'), $data),
                $request->filled('location') => $this->messages->sendLocation($request->user(), $conversation, (array) $request->validated('location'), $data),
                $request->filled('gif_id') => $this->messages->sendGif($request->user(), $conversation, (string) $request->input('gif_id'), $data),
                $type === Message::TYPE_TEXT => $this->messages->sendText($request->user(), $conversation, $data),
                default => $this->messages->sendAttachment(
                    $request->user(),
                    $conversation,
                    $request->file($type === Message::TYPE_VOICE ? 'voice' : 'attachment'),
                    $type,
                    $data + ['thumbnail' => $type === Message::TYPE_VIDEO ? $request->file('thumbnail') : null],
                ),
            };
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(
            (new MessageResource($message))->resolve($request) + ['client_id' => $request->input('client_id')],
            201
        );
    }

    /**
     * Find messages in this chat (newest first).
     */
    public function search(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        $user = $request->user();
        $results = $this->messages->search($conversation, $user, trim($validated['q']));

        return response()->json([
            'data' => $results->map(fn (Message $message) => [
                'id' => $message->id,
                'is_mine' => $message->isSentBy($user),
                'type' => $message->message_type,
                'preview' => $message->preview(120),
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Forward a message to up to five chats.
     */
    public function forward(ForwardMessageRequest $request, Message $message): JsonResponse
    {
        try {
            $messages = $this->messages->forward($request->user(), $message, $request->conversations());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'data' => MessageResource::collection(collect($messages))->resolve($request),
        ], 201);
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
