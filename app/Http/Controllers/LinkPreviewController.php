<?php

namespace App\Http\Controllers;

use App\Models\LinkPreview;
use App\Services\AttachmentService;
use App\Services\LinkPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LinkPreviewController extends Controller
{
    public function __construct(private readonly LinkPreviewService $previews) {}

    /**
     * Preview of a link while it is being typed (shown above the composer).
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate(['url' => ['required', 'string', 'max:2048']]);

        $preview = $this->previews->preview($validated['url']);

        return response()->json(['data' => $preview?->toPayload()]);
    }

    /**
     * The stored (re-encoded) image of a preview.
     */
    public function image(LinkPreview $linkPreview, AttachmentService $attachments): BinaryFileResponse
    {
        $disk = $attachments->disk();
        abort_if(! $linkPreview->image || ! $disk->exists($linkPreview->image), 404);

        // Private to the signed-in person (file responses default to public caching).
        return response()->file($disk->path($linkPreview->image), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ])->setPrivate();
    }
}
