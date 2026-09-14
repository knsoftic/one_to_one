<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\DisappearingMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DisappearingMessageController extends Controller
{
    public function __construct(private readonly DisappearingMessageService $disappearing) {}

    /**
     * Turn disappearing messages on (24 h / 7 days / 90 days) or off for a chat.
     */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('sendMessage', $conversation);
        abort_if(
            ($conversation->isGroup() || $conversation->isChannel()) && $conversation->only_admins_edit && ! $conversation->isAdmin($request->user()),
            403,
            'Only admins can change disappearing messages in this group.',
        );

        $validated = $request->validate([
            'seconds' => ['present', 'nullable', 'integer', Rule::in([0, ...DisappearingMessageService::DURATIONS])],
        ]);

        $seconds = (int) ($validated['seconds'] ?? 0) ?: null;

        if ($conversation->disappearing_seconds === $seconds) {
            return response()->json(['disappearing_seconds' => $seconds, 'message' => null]);
        }

        $notice = $this->disappearing->set($conversation, $request->user(), $seconds);

        return response()->json([
            'disappearing_seconds' => $seconds,
            'message' => (new MessageResource($notice))->resolve($request),
        ]);
    }
}
