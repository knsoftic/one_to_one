<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Services\CallLogService;
use App\Services\ContactService;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * K1 — Calls tab: call history, removing calls from it and clearing it.
 */
class CallLogController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly CallLogService $log,
        private readonly ContactService $contacts,
        private readonly MessageService $messages,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();

        $calls = $this->log->query($user)
            ->when($validated['before'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))
            ->with(['caller', 'callee'])
            ->orderByDesc('id')
            ->limit(self::PER_PAGE + 1)
            ->get();

        $hasMore = $calls->count() > self::PER_PAGE;
        $calls = $calls->take(self::PER_PAGE);
        $savedNames = $this->contacts->savedNames($user, $calls->map(fn (Call $call) => $call->otherParticipantId($user))->unique()->all());

        return response()->json([
            'data' => $calls->map(fn (Call $call) => $this->log->payload($call, $user, $savedNames, $request))->values(),
            'has_more' => $hasMore,
            'unseen_missed' => $this->log->unseenMissed($user),
        ]);
    }

    public function seen(Request $request): JsonResponse
    {
        return response()->json(['updated' => $this->log->markMissedSeen($request->user())]);
    }

    /** Remove one call from my call log (its history message is deleted for me). */
    public function destroy(Request $request, Call $call): JsonResponse
    {
        Gate::authorize('view', $call);
        $message = $call->message;
        abort_if($message === null || $message->isDeletedFor($request->user()), 404);

        $this->messages->deleteForMe($message, $request->user());

        return response()->json(['id' => $call->id]);
    }

    public function clear(Request $request): JsonResponse
    {
        return response()->json(['removed' => $this->log->clear($request->user())]);
    }
}
