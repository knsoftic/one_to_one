<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * D6 — Manage storage: how much space the photos, videos, voice messages, documents and
 * stickers in a person's chats take, the biggest files, and "delete for me" to clear them.
 */
class StorageUsageService
{
    /** "Larger than 5 MB". */
    public const LARGE_BYTES = 5 * 1024 * 1024;

    public const KINDS = ['photos', 'videos', 'voice', 'documents', 'gifs'];

    public const PAGE_SIZE = 60;

    /** One delete request clears at most this many files. */
    public const MAX_DELETE = 200;

    private const MEDIA_TYPES = [Message::TYPE_IMAGE, Message::TYPE_VIDEO, Message::TYPE_VOICE, Message::TYPE_DOCUMENT, Message::TYPE_STICKER];

    public function __construct(
        private readonly MessageService $messages,
        private readonly ChatCardService $cards,
    ) {}

    /**
     * @return array{total: array{bytes: int, files: int}, kinds: array<string, array{bytes: int, files: int}>, large: array{bytes: int, files: int}, chats: list<array<string, mixed>>}
     */
    public function summary(User $user): array
    {
        $kinds = array_fill_keys(self::KINDS, ['bytes' => 0, 'files' => 0]);
        $this->files($user)
            ->selectRaw($this->kindSql().' as kind, count(*) as files, coalesce(sum(attachment_size), 0) as bytes')
            ->groupBy('kind')
            ->toBase()
            ->get()
            ->each(function ($row) use (&$kinds) {
                $kinds[$row->kind] = ['bytes' => (int) $row->bytes, 'files' => (int) $row->files];
            });

        $large = $this->files($user)->where('attachment_size', '>=', self::LARGE_BYTES)
            ->selectRaw('count(*) as files, coalesce(sum(attachment_size), 0) as bytes')->toBase()->first();

        $rows = $this->files($user)
            ->selectRaw('conversation_id, count(*) as files, coalesce(sum(attachment_size), 0) as bytes')
            ->groupBy('conversation_id')
            ->orderByDesc('bytes')
            ->limit(100)
            ->toBase()
            ->get();

        $chats = $this->cards->for($user, $rows->pluck('conversation_id')->map(fn ($id) => (int) $id)->all());

        return [
            'total' => [
                'bytes' => array_sum(array_column($kinds, 'bytes')),
                'files' => array_sum(array_column($kinds, 'files')),
            ],
            'kinds' => $kinds,
            'large' => ['bytes' => (int) ($large->bytes ?? 0), 'files' => (int) ($large->files ?? 0)],
            'chats' => $rows
                ->filter(fn ($row) => isset($chats[(int) $row->conversation_id]))
                ->map(fn ($row) => $chats[(int) $row->conversation_id] + ['bytes' => (int) $row->bytes, 'files' => (int) $row->files])
                ->values()
                ->all(),
        ];
    }

    /**
     * Files of one chat, or the large files of every chat, biggest first.
     *
     * @return array{data: list<array<string, mixed>>, has_more: bool}
     */
    public function list(User $user, ?Conversation $conversation, bool $largeOnly, string $sort = 'size', int $page = 1): array
    {
        $page = max(1, $page);
        $messages = $this->files($user)
            ->when($conversation, fn ($q) => $q->where('conversation_id', $conversation->getKey()))
            ->when($largeOnly, fn ($q) => $q->where('attachment_size', '>=', self::LARGE_BYTES))
            ->when($sort === 'newest', fn ($q) => $q->orderByDesc('id'), fn ($q) => $q->orderByDesc('attachment_size')->orderByDesc('id'))
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE + 1)
            ->get(['id', 'conversation_id', 'sender_id', 'receiver_id', 'message_type', 'attachment', 'attachment_name', 'attachment_mime', 'attachment_size', 'attachment_meta', 'created_at']);

        $chats = $conversation ? [] : $this->cards->for($user, $messages->pluck('conversation_id')->unique()->all());

        return [
            'data' => $messages->take(self::PAGE_SIZE)->map(fn (Message $message) => $this->file($message, $user) + (
                $conversation ? [] : ['chat' => $chats[$message->conversation_id] ?? null]
            ))->values()->all(),
            'has_more' => $messages->count() > self::PAGE_SIZE,
        ];
    }

    /**
     * Delete files for this person only (like "Delete for me"); a file nobody can see anymore is removed.
     *
     * @param  list<int>  $ids
     * @return array{deleted: int, bytes: int}
     */
    public function delete(User $user, array $ids): array
    {
        $messages = $this->files($user)->whereKey(array_slice(array_unique($ids), 0, self::MAX_DELETE))->get();

        foreach ($messages as $message) {
            $this->messages->deleteForMe($message, $user);
        }

        return ['deleted' => $messages->count(), 'bytes' => (int) $messages->sum('attachment_size')];
    }

    /** Media and documents the person can still see (view once media is never kept). */
    private function files(User $user): Builder
    {
        return Message::query()
            ->visibleTo($user)
            ->where('deleted_for_everyone', false)
            ->whereNotNull('attachment')
            ->whereIn('message_type', self::MEDIA_TYPES)
            ->where(fn ($q) => $q->whereNull('attachment_meta->view_once')->orWhere('attachment_meta->view_once', false));
    }

    private function kindSql(): string
    {
        return "case when message_type = 'image' and attachment_mime = 'image/gif' then 'gifs'"
            ." when message_type = 'sticker' then 'gifs'"
            ." when message_type = 'image' then 'photos'"
            ." when message_type = 'video' then 'videos'"
            ." when message_type = 'voice' then 'voice'"
            ." else 'documents' end";
    }

    private function kind(Message $message): string
    {
        return match ($message->message_type) {
            Message::TYPE_IMAGE => $message->attachment_mime === 'image/gif' ? 'gifs' : 'photos',
            Message::TYPE_STICKER => 'gifs',
            Message::TYPE_VIDEO => 'videos',
            Message::TYPE_VOICE => 'voice',
            default => 'documents',
        };
    }

    /** @return array<string, mixed> */
    private function file(Message $message, User $user): array
    {
        $kind = $this->kind($message);
        $meta = $message->attachment_meta ?? [];
        $picture = in_array($kind, ['photos', 'gifs'], true) || ! empty($meta['thumbnail']);

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'kind' => $kind,
            'type' => $message->message_type,
            'name' => $message->attachment_name ?: match ($kind) {
                'photos' => 'Photo',
                'videos' => 'Video',
                'voice' => 'Voice message',
                'gifs' => $message->message_type === Message::TYPE_STICKER ? 'Sticker' : 'GIF',
                default => 'Document',
            },
            'size' => (int) $message->attachment_size,
            'duration' => $meta['duration'] ?? null,
            'is_mine' => $message->isSentBy($user),
            'created_at' => $message->created_at?->toIso8601String(),
            'thumbnail_url' => $picture ? route('messages.attachment', [$message, 'variant' => 'thumbnail'], false) : null,
            'url' => route('messages.attachment', $message, false),
            'download_url' => route('messages.attachment', [$message, 'download' => 1], false),
        ];
    }
}
