<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * Viewer-neutral payload for broadcasts (clients derive "is_mine" themselves).
     */
    public static function forBroadcast(Message $message): array
    {
        $message->loadMissing('replyTo');

        return (new self($message))->resolve(Request::create('/'));
    }

    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->getKey();
        $deleted = (bool) $this->deleted_for_everyone;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'receiver_id' => $this->receiver_id,
            'is_mine' => (int) $this->sender_id === (int) $viewerId,
            'type' => $deleted ? Message::TYPE_TEXT : $this->message_type,
            'body' => $deleted ? null : $this->message,
            'is_deleted' => $deleted,
            'is_edited' => (bool) $this->is_edited && ! $deleted,
            'edited_at' => $this->edited_at?->toIso8601String(),
            'attachment' => $this->when(! $deleted && $this->attachment !== null, fn () => $this->attachmentPayload()),
            'reply_to' => $this->replyPayload($viewerId),
            'status' => $this->status(),
            'sent_at' => ($this->sent_at ?? $this->created_at)?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'seen_at' => $this->seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function attachmentPayload(): array
    {
        $meta = $this->attachment_meta ?? [];

        // Relative URLs: valid for both participants whatever host they use.
        return [
            'url' => route('messages.attachment', $this->resource, false),
            'download_url' => route('messages.attachment', [$this->resource, 'download' => 1], false),
            'thumbnail_url' => ! empty($meta['thumbnail'])
                ? route('messages.attachment', [$this->resource, 'variant' => 'thumbnail'], false)
                : null,
            'name' => $this->attachment_name,
            'mime' => $this->attachment_mime,
            'size' => $this->attachment_size,
            'width' => $meta['width'] ?? null,
            'height' => $meta['height'] ?? null,
            'duration' => $meta['duration'] ?? null,
        ];
    }

    private function replyPayload(?int $viewerId): ?array
    {
        if (! $this->reply_to_id || ! $this->relationLoaded('replyTo') || ! $this->replyTo) {
            return null;
        }

        $reply = $this->replyTo;
        $hiddenForViewer = $viewerId !== null && $reply->involves($viewerId) && $reply->isDeletedFor($viewerId);

        return [
            'id' => $reply->id,
            'sender_id' => $reply->sender_id,
            'type' => $reply->message_type,
            'preview' => ($reply->deleted_for_everyone || $hiddenForViewer) ? 'This message was deleted' : $reply->preview(120),
            'is_deleted' => $reply->deleted_for_everyone || $hiddenForViewer,
        ];
    }
}
