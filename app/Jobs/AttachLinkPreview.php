<?php

namespace App\Jobs;

use App\Events\MessageUpdated;
use App\Models\Message;
use App\Services\LinkPreviewService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetch the preview of a message's link after the response was sent, then
 * add it to the message and update both people's screens.
 */
class AttachLinkPreview
{
    use Dispatchable;

    public function __construct(public int $messageId, public string $url) {}

    public function handle(LinkPreviewService $previews): void
    {
        try {
            $preview = $previews->preview($this->url);
        } catch (Throwable $e) {
            Log::info('Link preview failed: '.$e->getMessage(), ['url' => $this->url]);

            return;
        }

        $message = Message::find($this->messageId);

        // Deleted, edited to another link, or preview turned off meanwhile.
        if (! $preview || ! $message || $message->deleted_for_everyone || $message->link_preview_id !== null
            || $previews->firstUrl($message->message) !== $this->url) {
            return;
        }

        $message->forceFill(['link_preview_id' => $preview->getKey()])->save();
        $message->load(['replyTo', 'reactions', 'linkPreview']);

        // Everyone, including the sender's tab that sent it.
        broadcast(new MessageUpdated($message));
    }
}
