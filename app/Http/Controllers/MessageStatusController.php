<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Delivery receipts (✓✓) and read receipts (blue ✓✓).
 */
class MessageStatusController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    /**
     * The receiver's client acknowledges that messages reached their device.
     * Without ids, every pending message for the user is marked delivered.
     */
    public function delivered(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        // Only messages addressed to the current user can be updated (scoped in the service).
        $count = $this->messages->markDelivered($request->user(), $validated['ids'] ?? null);

        return response()->json(['updated' => $count]);
    }

    /**
     * The receiver opened the conversation: mark everything as seen.
     */
    public function seen(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $ids = $this->messages->markSeen($conversation, $request->user());

        return response()->json(['ids' => $ids, 'seen_at' => now()->toIso8601String()]);
    }
}
