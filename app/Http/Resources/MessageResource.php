<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Services\ConversationTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    private bool $channel = false;

    private ?int $viewerId = null;

    /**
     * Viewer-neutral payload for broadcasts (clients derive "is_mine" themselves).
     */
    public static function forBroadcast(Message $message): array
    {
        $message->loadMissing(Message::DISPLAY_RELATIONS);

        return (new self($message))->resolve(Request::create('/'));
    }

    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->getKey();
        $deleted = (bool) $this->deleted_for_everyone;
        // Channel updates (G11) come from the channel, and followers never see each other.
        $this->channel = $this->receiver_id === null && app(ConversationTypes::class)->isChannel((int) $this->conversation_id);
        $this->viewerId = $viewerId !== null ? (int) $viewerId : null;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'receiver_id' => $this->receiver_id,
            // Group chats (Phase 4): who wrote it, for people not in the reader's contacts.
            'sender_name' => $this->when($this->receiver_id === null && ! $this->channel && $this->relationLoaded('sender'), fn () => $this->sender?->name),
            'is_mine' => (int) $this->sender_id === (int) $viewerId,
            'type' => $deleted ? Message::TYPE_TEXT : $this->message_type,
            'body' => $deleted ? null : $this->message,
            'is_deleted' => $deleted,
            'is_edited' => (bool) $this->is_edited && ! $deleted,
            // [{emoji, count, user_ids}] — only when loaded, so partial updates never wipe them.
            'reactions' => $this->when($this->relationLoaded('reactions'), fn () => $deleted ? [] : $this->reactions
                ->groupBy('emoji')
                ->map(fn ($group, $emoji) => [
                    'emoji' => (string) $emoji,
                    'count' => $group->count(),
                    'user_ids' => $this->visibleUserIds($group->pluck('user_id')),
                ])
                ->values()
                ->all()),
            // Per viewer: only present when the query added it (never in broadcasts).
            'is_starred' => $this->when(array_key_exists('is_starred', $this->resource->getAttributes()), fn () => ! $deleted && (bool) $this->is_starred),
            'album_id' => $deleted ? null : ($this->attachment_meta['album'] ?? null),
            // @mentions (G4): [{id, name}] with the name as written in the text.
            'mentions' => $this->when(! $deleted && ! empty($this->attachment_meta['mentions']), fn () => array_values($this->attachment_meta['mentions'])),
            // A reply or reaction to a status update (S4): what the update was, and its picture while it lasts.
            'status_quote' => $this->when(! $deleted && ! empty($this->attachment_meta['status']), fn () => $this->statusQuote()),
            'view_once' => $this->when(! $deleted && ($this->attachment_meta['view_once'] ?? false), fn () => [
                'opened_at' => $this->attachment_meta['opened_at'] ?? null,
                'available' => $this->attachment !== null && empty($this->attachment_meta['opened_at']),
            ]),
            'forwarded' => ! $deleted && $this->forward_count > 0,
            'forwarded_many' => ! $deleted && $this->forward_count >= 5,
            'edited_at' => $this->edited_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'system' => $this->when($this->message_type === Message::TYPE_SYSTEM, fn () => array_filter([
                'event' => $this->attachment_meta['event'] ?? null,
                'seconds' => $this->attachment_meta['seconds'] ?? null,
                'text' => $this->resource->systemText(),
                // Group notices: who did it and to whom (names are shown as saved by the reader).
                'actor' => $this->attachment_meta['actor'] ?? null,
                'users' => $this->attachment_meta['users'] ?? null,
                'name' => $this->attachment_meta['name'] ?? null,
                'only_admins_send' => $this->attachment_meta['only_admins_send'] ?? null,
                'only_admins_edit' => $this->attachment_meta['only_admins_edit'] ?? null,
            ], fn ($value) => $value !== null)),
            'attachment' => $this->when(! $deleted && $this->attachment !== null, fn () => $this->attachmentPayload()),
            // Card for the first link (null = none); only when loaded.
            'link_preview' => $this->when($this->relationLoaded('linkPreview'), fn () => ! $deleted && $this->linkPreview?->isUsable()
                ? $this->linkPreview->toPayload()
                : null),
            'poll' => $this->when(! $deleted && $this->message_type === Message::TYPE_POLL, fn () => $this->pollPayload()),
            'contact' => $this->when(! $deleted && $this->message_type === Message::TYPE_CONTACT, fn () => [
                'name' => (string) ($this->attachment_meta['name'] ?? ''),
                'phones' => array_values($this->attachment_meta['phones'] ?? []),
                'user' => $this->attachment_meta['user'] ?? null,
                'vcard_url' => route('messages.contact', $this->resource, false),
            ]),
            'location' => $this->when(! $deleted && $this->message_type === Message::TYPE_LOCATION, fn () => [
                'lat' => (float) ($this->attachment_meta['lat'] ?? 0),
                'lng' => (float) ($this->attachment_meta['lng'] ?? 0),
                'accuracy' => $this->attachment_meta['accuracy'] ?? null,
                'updated_at' => $this->attachment_meta['updated_at'] ?? null,
                'live' => (bool) ($this->attachment_meta['live'] ?? false),
                'live_until' => $this->attachment_meta['live_until'] ?? null,
                'live_active' => $this->resource->isLiveLocationActive(),
                'stopped_at' => $this->attachment_meta['stopped_at'] ?? null,
            ]),
            'call' => $this->when(! $deleted && $this->message_type === Message::TYPE_CALL, fn () => [
                'id' => $this->attachment_meta['call_id'] ?? null,
                'type' => $this->attachment_meta['call_type'] ?? 'audio',
                'reason' => $this->attachment_meta['reason'] ?? null,
                'duration' => $this->attachment_meta['duration'] ?? null,
            ]),
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

        // View once: no links at all; the receiver opens it through a one-time link.
        if ($meta['view_once'] ?? false) {
            return [
                'url' => null,
                'download_url' => null,
                'thumbnail_url' => null,
                'name' => $this->attachment_name,
                'mime' => $this->attachment_mime,
                'size' => $this->attachment_size,
                'duration' => $meta['duration'] ?? null,
                'view_once' => true,
            ];
        }

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
            'hd' => (bool) ($meta['hd'] ?? false),
            'animated' => (bool) ($meta['animated'] ?? false),
        ];
    }

    /**
     * Options with vote counts and who voted (only two people are in a chat).
     */
    private function pollPayload(): array
    {
        $votes = $this->relationLoaded('pollVotes') ? $this->pollVotes : collect();

        return [
            'question' => (string) ($this->attachment_meta['question'] ?? ''),
            'multiple' => (bool) ($this->attachment_meta['multiple'] ?? false),
            'options' => collect($this->attachment_meta['options'] ?? [])->map(function ($option) use ($votes) {
                $voters = $votes->where('option', (int) $option['id'])->pluck('user_id')->map(fn ($id) => (int) $id)->values();

                return ['id' => (int) $option['id'], 'text' => (string) $option['text'], 'count' => $voters->count(), 'voter_ids' => $this->visibleUserIds($voters)];
            })->values()->all(),
            'total_voters' => $votes->pluck('user_id')->unique()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusQuote(): array
    {
        $quote = $this->attachment_meta['status'];
        $available = isset($quote['expires_at']) && Carbon::parse($quote['expires_at'])->isFuture();

        return [
            'id' => (int) ($quote['id'] ?? 0),
            'owner_id' => (int) ($quote['owner_id'] ?? 0),
            'type' => (string) ($quote['type'] ?? 'text'),
            'text' => $quote['text'] ?? null,
            'background' => $quote['background'] ?? null,
            'font' => $quote['font'] ?? null,
            'reaction' => (bool) ($quote['reaction'] ?? false),
            'available' => $available,
            'thumbnail_url' => $available && ! empty($quote['has_thumbnail'])
                ? route('statuses.media', ['status' => (int) $quote['id'], 'variant' => 'thumbnail'], false)
                : null,
        ];
    }

    /**
     * Who reacted / voted; in channels only the viewer themselves.
     *
     * @return list<int>
     */
    private function visibleUserIds($ids): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->values();

        return ($this->channel ? $ids->filter(fn (int $id) => $id === $this->viewerId)->values() : $ids)->all();
    }

    private function replyPayload(?int $viewerId): ?array
    {
        if (! $this->reply_to_id || ! $this->relationLoaded('replyTo') || ! $this->replyTo) {
            return null;
        }

        $reply = $this->replyTo;
        $hiddenForViewer = $viewerId !== null && ! $reply->isGroupMessage() && $reply->involves($viewerId) && $reply->isDeletedFor($viewerId);

        return [
            'id' => $reply->id,
            'sender_id' => $reply->sender_id,
            'conversation_id' => $reply->conversation_id,
            // A private reply to a group message (G7) says which group it came from.
            'group_name' => (int) $reply->conversation_id !== (int) $this->conversation_id && $reply->isGroupMessage()
                ? $reply->conversation?->name
                : null,
            'type' => $reply->message_type,
            'preview' => ($reply->deleted_for_everyone || $hiddenForViewer) ? 'This message was deleted' : $reply->preview(120),
            'is_deleted' => $reply->deleted_for_everyone || $hiddenForViewer,
        ];
    }
}
