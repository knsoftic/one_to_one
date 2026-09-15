<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\ChatExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * D7 — Export chat (chat menu → Export chat): a .txt, or a .zip with the media.
 */
class ChatExportController extends Controller
{
    public function __construct(private readonly ChatExportService $exports) {}

    public function download(Request $request, Conversation $conversation): BinaryFileResponse
    {
        Gate::authorize('view', $conversation);
        $user = $request->user();
        $withMedia = $request->boolean('media');

        abort_if($withMedia && ! $this->exports->zipAvailable(), 422, 'Exporting with media is not available on this server. Export without media instead.');

        @set_time_limit(300);

        try {
            $path = $withMedia ? $this->exports->exportZip($conversation, $user) : $this->exports->exportText($conversation, $user);
        } catch (RuntimeException $e) {
            abort(500, $e->getMessage());
        }

        return response()
            ->download($path, $this->exports->filename($conversation, $user, $withMedia ? 'zip' : 'txt'), [
                'Content-Type' => $withMedia ? 'application/zip' : 'text/plain; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend();
    }
}
