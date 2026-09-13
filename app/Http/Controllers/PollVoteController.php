<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\PollVote;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class PollVoteController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    /**
     * Set my answers (an empty list removes my vote).
     */
    public function update(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('vote', $message);

        $validated = $request->validate([
            'options' => ['present', 'array', 'max:'.PollVote::MAX_OPTIONS],
            'options.*' => ['integer', 'min:1', 'max:'.PollVote::MAX_OPTIONS],
        ]);

        try {
            $message = $this->messages->vote($message, $request->user(), $validated['options']);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['id' => $message->id, 'poll' => (new MessageResource($message))->resolve($request)['poll']]);
    }
}
