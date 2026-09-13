<?php

namespace App\Services;

use App\Events\MessageHidden;
use App\Events\MessageSent;
use App\Events\MessagesStatusUpdated;
use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class MessageService
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /**
     * Messages of a conversation visible to the user, oldest → newest,
     * using id-based cursor pagination ("load older" via $beforeId).
     *
     * @return array{messages: Collection<int, Message>, has_more: bool}
     */
    public function history(Conversation $conversation, User $user, ?int $beforeId = null, ?int $limit = null): array
    {
        $limit = max(1, min($limit ?? config('chat.messages_per_page'), 100));

        $messages = $conversation->messages()
            ->visibleTo($user)
            ->when($beforeId, fn ($q) => $q->where('id', '<', $beforeId))
            ->with('replyTo')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $messages->count() > $limit;

        return [
            'messages' => $messages->take($limit)->reverse()->values(),
            'has_more' => $hasMore,
        ];
    }

    /**
     * Persist a text message, update the conversation pointer and broadcast it.
     *
     * @param  array{message?: ?string, reply_to_id?: ?int}  $data
     */
    public function sendText(User $sender, Conversation $conversation, array $data): Message
    {
        return $this->create($sender, $conversation, [
            'message' => $this->cleanText($data['message'] ?? ''),
            'message_type' => Message::TYPE_TEXT,
            'reply_to_id' => $data['reply_to_id'] ?? null,
        ]);
    }

    /**
     * Persist an image, document or voice message.
     *
     * @param  array{message?: ?string, reply_to_id?: ?int, duration?: float|int|string|null}  $data
     */
    public function sendAttachment(User $sender, Conversation $conversation, UploadedFile $file, string $type, array $data): Message
    {
        $meta = $type === Message::TYPE_VOICE && isset($data['duration'])
            ? ['duration' => round((float) $data['duration'], 1)]
            : [];

        $stored = $this->attachments->store($file, $type, $meta);

        try {
            return $this->create($sender, $conversation, $stored + [
                'message' => $type === Message::TYPE_VOICE ? null : ($this->cleanText($data['message'] ?? '') ?: null),
                'message_type' => $type,
                'reply_to_id' => $data['reply_to_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            $this->attachments->delete(new Message($stored));

            throw $e;
        }
    }

    /**
     * Edit the text of a message.
     */
    public function edit(Message $message, string $text): Message
    {
        $message->forceFill([
            'message' => $this->cleanText($text),
            'is_edited' => true,
            'edited_at' => now(),
        ])->save();

        $message->load('replyTo');

        broadcast(new MessageUpdated($message))->toOthers();

        return $message;
    }

    /**
     * Hide a message for one participant only.
     */
    public function deleteForMe(Message $message, User $user): void
    {
        $column = $message->isSentBy($user) ? 'deleted_for_sender' : 'deleted_for_receiver';
        $message->forceFill([$column => true])->save();

        // Nobody can see it anymore: free the stored file.
        if ($message->deleted_for_sender && $message->deleted_for_receiver && $message->attachment) {
            $this->attachments->delete($message);
            $message->forceFill(['attachment' => null, 'attachment_meta' => null])->save();
        }

        broadcast(new MessageHidden($message->id, $message->conversation_id, $user->getKey()))->toOthers();
    }

    /**
     * Delete a message for both participants. Content and files are wiped.
     */
    public function deleteForEveryone(Message $message): Message
    {
        $this->attachments->delete($message);

        $message->forceFill([
            'deleted_for_everyone' => true,
            'message' => null,
            'attachment' => null,
            'attachment_name' => null,
            'attachment_mime' => null,
            'attachment_size' => null,
            'attachment_meta' => null,
            'is_edited' => false,
            'edited_at' => null,
        ])->save();

        $message->load('replyTo');

        broadcast(new MessageUpdated($message))->toOthers();

        return $message;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function create(User $sender, Conversation $conversation, array $attributes): Message
    {
        $message = DB::transaction(function () use ($sender, $conversation, $attributes) {
            /** @var Message $message */
            $message = $conversation->messages()->create($attributes + [
                'sender_id' => $sender->getKey(),
                'receiver_id' => $conversation->otherParticipantId($sender),
                'sent_at' => now(),
            ]);

            $conversation->forceFill(['last_message_id' => $message->getKey()])->save();

            return $message;
        });

        $message->load('replyTo');

        // Broadcast after commit; the sending browser tab is excluded (X-Socket-ID).
        broadcast(new MessageSent($message))->toOthers();

        return $message;
    }

    /**
     * Mark messages received by $receiver as delivered (all pending ones, or
     * only $ids) and notify the senders.
     *
     * @param  list<int>|null  $ids
     * @return int number of messages updated
     */
    public function markDelivered(User $receiver, ?array $ids = null): int
    {
        $pending = Message::query()
            ->where('receiver_id', $receiver->getKey())
            ->whereNull('delivered_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->limit(500)
            ->get(['id', 'conversation_id', 'sender_id']);

        if ($pending->isEmpty()) {
            return 0;
        }

        $now = now();

        Message::query()
            ->whereIn('id', $pending->pluck('id'))
            ->whereNull('delivered_at')
            ->update(['delivered_at' => $now]);

        $pending->groupBy('conversation_id')->each(function ($group, $conversationId) use ($now) {
            broadcast(new MessagesStatusUpdated(
                (int) $conversationId,
                (int) $group->first()->sender_id,
                $group->pluck('id')->map(fn ($id) => (int) $id)->all(),
                Message::STATUS_DELIVERED,
                $now->toIso8601String(),
            ));
        });

        return $pending->count();
    }

    /**
     * Mark every unseen message the reader received in a conversation as seen.
     *
     * @return list<int> ids of messages that were updated
     */
    public function markSeen(Conversation $conversation, User $reader): array
    {
        $ids = $conversation->messages()
            ->where('receiver_id', $reader->getKey())
            ->whereNull('seen_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $now = now();

        // Reading the chat also clears its entries in the notification centre.
        $reader->unreadNotifications()
            ->where('data->conversation_id', $conversation->getKey())
            ->update(['read_at' => $now]);

        if ($ids === []) {
            return [];
        }

        DB::transaction(function () use ($ids, $now) {
            Message::query()->whereIn('id', $ids)->whereNull('delivered_at')->update(['delivered_at' => $now]);
            Message::query()->whereIn('id', $ids)->whereNull('seen_at')->update(['seen_at' => $now]);
        });

        broadcast(new MessagesStatusUpdated(
            $conversation->getKey(),
            $conversation->otherParticipantId($reader),
            $ids,
            Message::STATUS_SEEN,
            $now->toIso8601String(),
        ));

        return $ids;
    }

    /**
     * Normalise user text: strip ASCII control characters (keeping newlines
     * and tabs) and bidi override characters (used for text spoofing), unify
     * line endings and collapse excessive blank lines. Emoji joiners are kept.
     */
    public function cleanText(?string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';
        $text = preg_replace("/\n{4,}/", "\n\n\n", $text) ?? '';

        return trim($text);
    }
}
