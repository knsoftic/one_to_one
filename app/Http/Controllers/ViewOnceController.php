<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\AttachmentService;
use App\Services\ViewOnceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ViewOnceController extends Controller
{
    public function __construct(private readonly ViewOnceService $viewOnce) {}

    /**
     * Open a view once message (once, receiver only).
     */
    public function open(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message);

        try {
            $result = $this->viewOnce->open($message, $request->user());
        } catch (RuntimeException $e) {
            abort(ViewOnceService::isViewOnce($message) && ! $message->isSentBy($request->user()) ? 410 : 403, $e->getMessage());
        }

        return response()->json([
            'url' => $result['url'],
            'message' => (new MessageResource($result['message']))->resolve($request),
        ]);
    }

    /**
     * The media behind the short-lived link (never cached).
     */
    public function file(Request $request, Message $message, AttachmentService $attachments): BinaryFileResponse
    {
        Gate::authorize('view', $message);
        abort_unless($this->viewOnce->canLoad($message, $request->user()), 410, 'This view once message is no longer available.');

        $path = $attachments->path($message);
        abort_if($path === null, 410, 'This view once message is no longer available.');

        // Private to the signed-in person (file responses default to public caching).
        return response()->file($path, [
            'Content-Type' => $message->attachment_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; sandbox",
        ])->setPrivate();
    }
}
