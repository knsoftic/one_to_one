<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Rules\SingleEmoji;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Emoji reactions on messages (one per person, replaced when changed).
 */
class MessageReactionController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function update(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('react', $message);
        $validated = $request->validate(['emoji' => ['required', 'string', new SingleEmoji]]);

        return $this->respond($request, $this->messages->react($message, $request->user(), $validated['emoji']));
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('react', $message);

        return $this->respond($request, $this->messages->react($message, $request->user(), null));
    }

    private function respond(Request $request, Message $message): JsonResponse
    {
        $resource = (new MessageResource($message))->resolve($request);

        return response()->json(['id' => $message->id, 'reactions' => $resource['reactions'] ?? []]);
    }
}
