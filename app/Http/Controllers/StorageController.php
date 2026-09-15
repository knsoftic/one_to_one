<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\StorageUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * D6 — Settings → Storage and data → Manage storage.
 */
class StorageController extends Controller
{
    public function __construct(private readonly StorageUsageService $storage) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->storage->summary($request->user()));
    }

    /** ?conversation={id} for one chat, ?large=1 for big files everywhere; ?sort=size|newest, ?page. */
    public function files(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation' => ['nullable', 'integer', 'min:1'],
            'large' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['size', 'newest'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $conversation = null;
        if (isset($validated['conversation'])) {
            $conversation = Conversation::query()->findOrFail($validated['conversation']);
            Gate::authorize('view', $conversation);
        }

        return response()->json($this->storage->list(
            $request->user(),
            $conversation,
            $request->boolean('large'),
            $validated['sort'] ?? 'size',
            (int) ($validated['page'] ?? 1),
        ));
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message_ids' => ['required', 'array', 'min:1', 'max:'.StorageUsageService::MAX_DELETE],
            'message_ids.*' => ['integer', 'min:1'],
        ]);

        $result = $this->storage->delete($request->user(), array_map('intval', $validated['message_ids']));

        return response()->json($result + [
            'message' => $result['deleted'] === 1 ? '1 file deleted for you.' : "{$result['deleted']} files deleted for you.",
        ]);
    }
}
