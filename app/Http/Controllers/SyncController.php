<?php

namespace App\Http\Controllers;

use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * AJAX polling endpoint used when WebSockets are unavailable.
 */
class SyncController extends Controller
{
    public function __construct(private readonly SyncService $sync) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['required', 'date'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
        ]);

        // Never replay more than one day of changes in a single poll.
        $since = Carbon::parse($validated['since'])->max(now()->subDay());

        return response()->json($this->sync->since(
            $request,
            $request->user(),
            $since,
            isset($validated['conversation_id']) ? (int) $validated['conversation_id'] : null,
        ));
    }
}
