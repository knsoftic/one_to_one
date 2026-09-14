<?php

namespace App\Services;

use App\Events\MessageHidden;
use App\Events\MessageSent;
use App\Events\MessagesStatusUpdated;
use App\Events\MessageUpdated;
use App\Jobs\AttachLinkPreview;
use App\Jobs\SendReadPush;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Sticker;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class MessageService
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly LinkPreviewService $linkPreviews,
    ) {}

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
            ->with(Message::DISPLAY_RELATIONS)
            ->withViewerState($user)
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
     * Search a conversation's messages (text and file names) the user can see, newest first.
     *
     * @return Collection<int, Message>
     */
    public function search(Conversation $conversation, User $user, string $term, int $limit = 50): Collection
    {
        $like = '%'.addcslashes($term, '\\%_').'%';

        return $conversation->messages()
            ->visibleTo($user)
            ->where('deleted_for_everyone', false)
            ->where(fn ($q) => $q->where('message', 'like', $like)->orWhere('attachment_name', 'like', $like))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'conversation_id', 'sender_id', 'receiver_id', 'message', 'message_type', 'attachment_name', 'deleted_for_everyone', 'created_at']);
    }

    /**
     * Persist a text message, update the conversation pointer and broadcast it.
     * The first link gets a preview unless `link_preview` is false: a cached
     * one right away, otherwise fetched after the response.
     *
     * @param  array{message?: ?string, reply_to_id?: ?int, link_preview?: bool|string|int|null}  $data
     */
    public function sendText(User $sender, Conversation $conversation, array $data): Message
    {
        $text = $this->cleanText($data['message'] ?? '');
        $wantsPreview = filter_var($data['link_preview'] ?? true, FILTER_VALIDATE_BOOL) && $this->linkPreviews->enabled();
        $url = $wantsPreview ? $this->linkPreviews->firstUrl($text) : null;
        $preview = $this->linkPreviews->cached($url);

        $message = $this->create($sender, $conversation, [
            'message' => $text,
            'message_type' => Message::TYPE_TEXT,
            'reply_to_id' => $data['reply_to_id'] ?? null,
            'link_preview_id' => $preview?->getKey(),
        ]);

        if ($url !== null && ! $preview && $this->linkPreviews->needsFetch($url)) {
            AttachLinkPreview::dispatchAfterResponse($message->getKey(), $url);
        }

        return $message;
    }

    /**
     * Persist an image, video, document or voice message.
     *
     * @param  array{message?: ?string, reply_to_id?: ?int, duration?: float|int|string|null, thumbnail?: ?UploadedFile, album_id?: ?string}  $data
     */
    public function sendAttachment(User $sender, Conversation $conversation, UploadedFile $file, string $type, array $data): Message
    {
        $meta = in_array($type, [Message::TYPE_VOICE, Message::TYPE_VIDEO], true) && isset($data['duration'])
            ? ['duration' => round((float) $data['duration'], 1)]
            : [];

        $viewOnce = filter_var($data['view_once'] ?? false, FILTER_VALIDATE_BOOL)
            && in_array($type, [Message::TYPE_IMAGE, Message::TYPE_VIDEO, Message::TYPE_VOICE], true)
            && ! str_ends_with(strtolower($file->getClientOriginalName()), '.gif')
            // Nobody else could ever open it in "Message yourself".
            && ! $conversation->isSelf();

        if ($viewOnce) {
            $meta['view_once'] = true;
        } elseif (in_array($type, [Message::TYPE_IMAGE, Message::TYPE_VIDEO], true) && ! empty($data['album_id'])) {
            $meta['album'] = strtolower($data['album_id']);
        }

        $stored = $this->attachments->store($file, $type, $meta, $data['thumbnail'] ?? null, ($data['quality'] ?? null) === 'hd');

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
     * A notice in the chat written on behalf of a user (e.g. a changed setting).
     *
     * @param  array<string, mixed>  $meta
     */
    public function systemNotice(User $user, Conversation $conversation, array $meta): Message
    {
        return $this->create($user, $conversation, [
            'message_type' => Message::TYPE_SYSTEM,
            'attachment_meta' => $meta,
        ]);
    }

    /**
     * Create a poll (question + 2–12 options, optionally several answers).
     *
     * @param  array{question: string, options: list<string>, multiple?: bool|string|null}  $poll
     * @param  array{reply_to_id?: ?int}  $data
     */
    public function sendPoll(User $sender, Conversation $conversation, array $poll, array $data): Message
    {
        $clean = fn (string $text, int $limit) => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $this->cleanText($text))), 0, $limit);

        $options = collect($poll['options'])
            ->map(fn ($text) => $clean((string) $text, 100))
            ->filter(fn ($text) => $text !== '')
            ->values()
            ->map(fn ($text, $index) => ['id' => $index + 1, 'text' => $text])
            ->all();

        if (count($options) < 2) {
            throw new \RuntimeException('A poll needs at least 2 options.');
        }

        return $this->create($sender, $conversation, [
            'message_type' => Message::TYPE_POLL,
            'reply_to_id' => $data['reply_to_id'] ?? null,
            'attachment_meta' => [
                'question' => $clean((string) $poll['question'], 255),
                'options' => $options,
                'multiple' => filter_var($poll['multiple'] ?? false, FILTER_VALIDATE_BOOL),
            ],
        ]);
    }

    /**
     * Replace the user's answers in a poll (an empty list removes the vote).
     *
     * @param  list<int>  $options
     */
    public function vote(Message $message, User $user, array $options): Message
    {
        $valid = collect($message->attachment_meta['options'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);
        $options = collect($options)->map(fn ($id) => (int) $id)->unique()->values();

        if ($options->diff($valid)->isNotEmpty()) {
            throw new \RuntimeException('That option is not part of this poll.');
        }

        if ($options->count() > 1 && ! ($message->attachment_meta['multiple'] ?? false)) {
            throw new \RuntimeException('Only one answer can be chosen in this poll.');
        }

        DB::transaction(function () use ($message, $user, $options) {
            $message->pollVotes()->where('user_id', $user->getKey())->delete();
            foreach ($options as $option) {
                $message->pollVotes()->create(['user_id' => $user->getKey(), 'option' => $option, 'created_at' => now()]);
            }
        });

        // Picked up by the polling sync (changed messages are found by updated_at).
        $message->touch();
        $message->load(Message::DISPLAY_RELATIONS);

        broadcast(new MessageUpdated($message))->toOthers();

        return $message;
    }

    /**
     * Send a contact card. When one of its numbers belongs to someone in the
     * sender's own saved contacts, the card links to that account ("Message").
     * Numbers are never looked up among all users, so cards cannot be used to
     * find out who is registered.
     *
     * @param  array{name: string, phones: list<string>}  $contact
     * @param  array{reply_to_id?: ?int}  $data
     */
    public function sendContact(User $sender, Conversation $conversation, array $contact, array $data): Message
    {
        $phones = collect($contact['phones'])
            ->map(fn ($phone) => Phone::normalize((string) $phone))
            ->filter(fn ($phone) => strlen(ltrim($phone, '+')) >= 5)
            ->unique()
            ->values()
            ->all();

        $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', (string) $contact['name']) ?? '') ?? '');
        $name = $name !== '' ? mb_substr($name, 0, 100) : ($phones[0] ?? 'Contact');

        $match = $sender->contacts()
            ->with('contactUser:id,name,username,phone,status')
            ->get()
            ->first(fn ($saved) => $saved->contactUser?->isActive()
                && collect($phones)->contains(fn ($phone) => Phone::matches($phone, (string) $saved->contactUser->phone)));

        return $this->create($sender, $conversation, [
            'message_type' => Message::TYPE_CONTACT,
            'reply_to_id' => $data['reply_to_id'] ?? null,
            'attachment_meta' => array_filter([
                'name' => $name,
                'phones' => $phones,
                'user' => $match ? [
                    'id' => $match->contactUser->id,
                    'name' => $match->contactUser->name,
                    'username' => $match->contactUser->username,
                ] : null,
            ]),
        ]);
    }

    /**
     * Share the current location, or a live location for 15 min / 1 h / 8 h.
     *
     * @param  array{lat: float|string, lng: float|string, accuracy?: float|string|null, live_minutes?: int|string|null}  $location
     * @param  array{reply_to_id?: ?int}  $data
     */
    public function sendLocation(User $sender, Conversation $conversation, array $location, array $data): Message
    {
        $minutes = isset($location['live_minutes']) ? (int) $location['live_minutes'] : null;

        return $this->create($sender, $conversation, [
            'message_type' => Message::TYPE_LOCATION,
            'reply_to_id' => $data['reply_to_id'] ?? null,
            'attachment_meta' => $this->locationPoint($location) + array_filter([
                'live' => $minutes !== null,
                'live_until' => $minutes !== null ? now()->addMinutes($minutes)->toIso8601String() : null,
            ]),
        ]);
    }

    /**
     * New position of a live location (sent by the sharing device).
     *
     * @param  array{lat: float|string, lng: float|string, accuracy?: float|string|null}  $location
     */
    public function updateLiveLocation(Message $message, array $location): Message
    {
        $message->forceFill(['attachment_meta' => $this->locationPoint($location) + ($message->attachment_meta ?? [])])->save();
        $message->load(Message::DISPLAY_RELATIONS);

        broadcast(new MessageUpdated($message))->toOthers();

        return $message;
    }

    public function stopLiveLocation(Message $message): Message
    {
        $message->forceFill(['attachment_meta' => ['stopped_at' => now()->toIso8601String()] + ($message->attachment_meta ?? [])])->save();
        $message->load(Message::DISPLAY_RELATIONS);

        broadcast(new MessageUpdated($message))->toOthers();

        return $message;
    }

    /**
     * @return array{lat: float, lng: float, accuracy: ?int, updated_at: string}
     */
    private function locationPoint(array $location): array
    {
        return [
            'lat' => round((float) $location['lat'], 6),
            'lng' => round((float) $location['lng'], 6),
            'accuracy' => isset($location['accuracy']) ? (int) round((float) $location['accuracy']) : null,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Send one of the user's stickers (the file is copied into the message).
     *
     * @param  array{reply_to_id?: ?int}  $data
     */
    public function sendSticker(User $sender, Conversation $conversation, Sticker $sticker, array $data): Message
    {
        $stored = app(StickerService::class)->attachmentFor($sticker);

        try {
            return $this->create($sender, $conversation, $stored + [
                'message_type' => Message::TYPE_STICKER,
                'reply_to_id' => $data['reply_to_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            $this->attachments->delete(new Message($stored));

            throw $e;
        }
    }

    /**
     * Send a GIF chosen in GIF search (downloaded and stored by the server).
     *
     * @param  array{message?: ?string, reply_to_id?: ?int}  $data
     *
     * @throws \RuntimeException when the GIF cannot be downloaded
     */
    public function sendGif(User $sender, Conversation $conversation, string $gifId, array $data): Message
    {
        $stored = app(GifService::class)->download($gifId);

        try {
            return $this->create($sender, $conversation, $stored + [
                'message' => $this->cleanText($data['message'] ?? '') ?: null,
                'message_type' => Message::TYPE_IMAGE,
                'reply_to_id' => $data['reply_to_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            $this->attachments->delete(new Message($stored));

            throw $e;
        }
    }

    /**
     * Forward a message to other conversations. Files are copied, so each
     * message stays independent (deleting one never removes the other's file).
     *
     * @param  iterable<Conversation>  $conversations
     * @return list<Message>
     */
    public function forward(User $user, Message $original, iterable $conversations): array
    {
        $forwarded = [];

        foreach ($conversations as $conversation) {
            $attributes = [
                'message' => $original->message,
                'message_type' => $original->message_type,
                'forward_count' => min(65535, (int) $original->forward_count + 1),
                'link_preview_id' => $original->link_preview_id,
            ];

            if ($original->attachment) {
                $attributes += $this->attachments->duplicate($original);
            }

            // A forwarded poll starts again without votes.
            if ($original->message_type === Message::TYPE_CONTACT || $original->message_type === Message::TYPE_POLL) {
                $attributes['attachment_meta'] = $original->attachment_meta;
            }

            // A forwarded location is the last known point, never live.
            if ($original->message_type === Message::TYPE_LOCATION) {
                $meta = $original->attachment_meta ?? [];
                $attributes['attachment_meta'] = [
                    'lat' => $meta['lat'] ?? 0.0,
                    'lng' => $meta['lng'] ?? 0.0,
                    'accuracy' => $meta['accuracy'] ?? null,
                    'updated_at' => $meta['updated_at'] ?? now()->toIso8601String(),
                ];
            }

            try {
                $forwarded[] = $this->create($user, $conversation, $attributes);
            } catch (Throwable $e) {
                if (isset($attributes['attachment'])) {
                    $this->attachments->delete(new Message($attributes));
                }

                throw $e;
            }
        }

        return $forwarded;
    }

    /**
     * Set (or with null remove) the user's emoji reaction; one per person per message.
     */
    public function react(Message $message, User $user, ?string $emoji): Message
    {
        if ($emoji === null) {
            $message->reactions()->where('user_id', $user->getKey())->delete();
        } else {
            $message->reactions()->updateOrCreate(['user_id' => $user->getKey()], ['emoji' => $emoji]);
        }

        // Picked up by the polling sync (changed messages are found by updated_at).
        $message->touch();
        $message->load(Message::DISPLAY_RELATIONS);

        broadcast(new MessageUpdated($message))->toOthers();

        return $message;
    }

    /**
     * Edit the text of a message.
     */
    public function edit(Message $message, string $text): Message
    {
        $text = $this->cleanText($text);
        $url = $this->linkPreviews->enabled() ? $this->linkPreviews->firstUrl($text) : null;
        $linkChanged = $url !== $this->linkPreviews->firstUrl($message->message);
        $preview = $linkChanged ? $this->linkPreviews->cached($url) : null;

        $message->forceFill([
            'message' => $text,
            'is_edited' => true,
            'edited_at' => now(),
        ] + ($linkChanged ? ['link_preview_id' => $preview?->getKey()] : []))->save();

        $message->load(Message::DISPLAY_RELATIONS);

        broadcast(new MessageUpdated($message))->toOthers();

        if ($linkChanged && $url !== null && ! $preview && $this->linkPreviews->needsFetch($url)) {
            AttachLinkPreview::dispatchAfterResponse($message->getKey(), $url);
        }

        return $message;
    }

    /**
     * Hide a message for one participant only.
     */
    public function deleteForMe(Message $message, User $user): void
    {
        $column = $message->isSentBy($user) ? 'deleted_for_sender' : 'deleted_for_receiver';
        // A note to self is both sent and received by the same person.
        $message->forceFill($message->sender_id === $message->receiver_id
            ? ['deleted_for_sender' => true, 'deleted_for_receiver' => true]
            : [$column => true])->save();
        $message->stars()->where('user_id', $user->getKey())->delete();

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
            'link_preview_id' => null,
        ])->save();

        $message->reactions()->delete();
        $message->pollVotes()->delete();
        $message->stars()->delete();
        app(PinService::class)->unpin($message);
        $message->load(Message::DISPLAY_RELATIONS);

        broadcast(new MessageUpdated($message))->toOthers();

        return $message;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function create(User $sender, Conversation $conversation, array $attributes): Message
    {
        // Disappearing messages (M21): new messages get their end time; notices never disappear.
        if ($conversation->disappearing_seconds && ! in_array($attributes['message_type'] ?? null, [Message::TYPE_SYSTEM, Message::TYPE_CALL], true)) {
            $attributes['expires_at'] ??= now()->addSeconds($conversation->disappearing_seconds);
        }

        $message = DB::transaction(function () use ($sender, $conversation, $attributes) {
            /** @var Message $message */
            $message = $conversation->messages()->make($attributes + [
                'sender_id' => $sender->getKey(),
                'receiver_id' => $conversation->otherParticipantId($sender),
                'sent_at' => now(),
            ]);

            // Notes to self are delivered and read the moment they are sent (C7).
            if ($conversation->isSelf()) {
                $message->forceFill(['delivered_at' => now(), 'seen_at' => now()]);
            }

            $message->save();

            $conversation->forceFill(['last_message_id' => $message->getKey()])->save();

            return $message;
        });

        $message->load(['replyTo', 'linkPreview']);

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

        // Reading the chat also clears its entries in the notification centre
        // and a "Mark as unread" mark (C4).
        $reader->unreadNotifications()
            ->where('data->conversation_id', $conversation->getKey())
            ->update(['read_at' => $now]);
        ChatSetting::query()
            ->where('user_id', $reader->getKey())
            ->where('conversation_id', $conversation->getKey())
            ->where('marked_unread', true)
            ->update(['marked_unread' => false]);

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

        // Remove the chat's notification from the reader's phones.
        if (app(PushService::class)->enabled()) {
            SendReadPush::dispatchAfterResponse($reader->getKey(), $conversation->getKey());
        }

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
