<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\MessageService;
use App\Services\PresenceService;
use App\Services\TypingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PresenceController extends Controller
{
    public function __construct(
        private readonly PresenceService $presence,
        private readonly MessageService $messages,
        private readonly TypingService $typing,
    ) {}

    /**
     * Periodic heartbeat from an open app tab: keeps "online" fresh and
     * confirms delivery of any messages that arrived while away.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->presence->touch($user, force: true);
        $delivered = $this->messages->markDelivered($user);

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'delivered' => $delivered,
        ]);
    }

    /**
     * Sent with navigator.sendBeacon() when the last tab is closed.
     */
    public function offline(Request $request): Response
    {
        $this->presence->markOffline($request->user());

        return response()->noContent();
    }

    /**
     * Typing indicator (throttled on the client; blocked users cannot signal).
     */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('sendMessage', $conversation);

        $validated = $request->validate(['typing' => ['required', 'boolean']]);

        $this->typing->set($conversation, $request->user(), (bool) $validated['typing']);

        return response()->json(['ok' => true]);
    }
}
