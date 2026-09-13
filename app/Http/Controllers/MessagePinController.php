<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\PinnedMessage;
use App\Services\PinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Pin a message to the top of its chat for both participants.
 */
class MessagePinController extends Controller
{
    public function __construct(private readonly PinService $pins) {}

    public function store(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('pin', $message);
        $validated = $request->validate(['duration' => ['required', 'integer', Rule::in(PinnedMessage::DURATIONS)]]);

        $this->pins->pin($message, $request->user(), (int) $validated['duration']);

        return $this->respond($request, $message);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('pin', $message);

        $this->pins->unpin($message);

        return $this->respond($request, $message);
    }

    private function respond(Request $request, Message $message): JsonResponse
    {
        return response()->json([
            'conversation_id' => $message->conversation_id,
            'pinned_messages' => $this->pins->visibleFor($message->conversation, $request->user()),
        ]);
    }
}
