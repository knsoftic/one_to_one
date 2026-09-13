<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Sticker;
use App\Services\AttachmentService;
use App\Services\StickerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "My stickers": list, make from a photo/emoji, save from a chat, delete.
 */
class StickerController extends Controller
{
    public function __construct(private readonly StickerService $stickers) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->stickers()
                ->orderByDesc('used_at')
                ->orderByDesc('id')
                ->limit(Sticker::MAX_PER_USER)
                ->get()
                ->map(fn (Sticker $sticker) => $sticker->toPayload())
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'sticker' => [
                'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp',
                'max:'.config('chat.uploads.sticker.max_kb'), 'dimensions:max_width=4096,max_height=4096',
            ],
        ], ['sticker.*' => 'Stickers can be made from JPG, PNG or WebP images up to 2 MB.']);

        try {
            $sticker = $this->stickers->create($request->user(), $request->file('sticker'));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($sticker->toPayload(), 201);
    }

    /**
     * Save a sticker from a chat to "My stickers".
     */
    public function saveFromMessage(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message);
        abort_if($message->deleted_for_everyone || $message->message_type !== Message::TYPE_STICKER, 422, 'Only stickers can be saved.');

        try {
            $sticker = $this->stickers->saveFromMessage($request->user(), $message);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($sticker->toPayload(), 201);
    }

    public function image(Request $request, Sticker $sticker, AttachmentService $attachments): BinaryFileResponse
    {
        abort_unless((int) $sticker->user_id === (int) $request->user()->getKey(), 404);
        abort_unless($attachments->disk()->exists($sticker->path), 404);

        // Private to the signed-in person (file responses default to public caching).
        return response()->file($attachments->disk()->path($sticker->path), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ])->setPrivate();
    }

    public function destroy(Request $request, Sticker $sticker): JsonResponse
    {
        abort_unless((int) $sticker->user_id === (int) $request->user()->getKey(), 404);
        $this->stickers->delete($sticker);

        return response()->json(['id' => $sticker->id]);
    }
}
