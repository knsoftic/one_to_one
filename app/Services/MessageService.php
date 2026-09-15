<?php

namespace App\Services;

use App\Events\MessageHidden;
use App\Events\MessageSent;
use App\Events\MessagesStatusUpdated;
use App\Events\MessageUpdated;
use App\Jobs\AttachLinkPreview;
use App\Jobs\FanOutBroadcast;
use App\Jobs\SendReadPush;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Sticker;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
            // Group chats show who wrote each message.
            ->when($conversation->isGroup(), fn ($q) => $q->with('sender:id,name'))
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

    /** D1 — the kinds of things in a chat's media gallery. */
    public const GALLERY_KINDS = ['media', 'docs', 'links'];

    /**
     * D1 — photos and videos, documents, or messages with links that the user can see, newest first.
     *
     * @return array{messages: Collection<int, Message>, has_more: bool}
     */
    public function gallery(Conversation $conversation, User $user, string $kind, ?int $beforeId = null, int $limit = 60): array
    {
        $messages = $this->galleryQuery($conversation, $user, $kind)
            ->when($beforeId, fn ($q) => $q->where('id', '<', $beforeId))
            ->with(Message::DISPLAY_RELATIONS)
            ->when($conversation->isGroup(), fn ($q) => $q->with('sender:id,name'))
            ->withViewerState($user)
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        return ['messages' => $messages->take($limit)->values(), 'has_more' => $messages->count() > $limit];
    }

    /**
     * @return array{media: int, docs: int, links: int}
     */
    public function galleryCounts(Conversation $conversation, User $user): array
    {
        return collect(self::GALLERY_KINDS)->mapWithKeys(fn (string $kind) => [$kind => $this->galleryQuery($conversation, $user, $kind)->count()])->all();
    }

    private function galleryQuery(Conversation $conversation, User $user, string $kind): HasMany
    {
        $query = $conversation->messages()->visibleTo($user)->where('deleted_for_everyone', false);

        return match ($kind) {
            // View once media never shows up again (M22).
            'media' => $query->whereIn('message_type', [Message::TYPE_IMAGE, Message::TYPE_VIDEO])->whereNotNull('attachment')
                ->where(fn ($q) => $q->whereNull('attachment_meta->view_once')->orWhere('attachment_meta->view_once', false)),
            'docs' => $query->where('message_type', Message::TYPE_DOCUMENT)->whereNotNull('attachment'),
            default => $query->where('message_type', Message::TYPE_TEXT)
                ->where(fn ($q) => $q->whereNotNull('link_preview_id')->orWhere('message', 'like', '%http://%')->orWhere('message', 'like', '%https://%')),
        };
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
            'attachment_meta' => $this->mentionMeta($sender, $conversation, $text, $data['mentions'] ?? []) ?: null,
        ]);

        if ($url !== null && ! $preview && $this->linkPreviews->needsFetch($url)) {
            AttachLinkPreview::dispatchAfterResponse($message->getKey(), $url);
        }

        return $message;
    }

    /**
     * X8 — a business's automatic away message or greeting.
     */
    public function sendAutoReply(User $business, Conversation $conversation, string $text, string $kind): Message
    {
        $text = $this->cleanText($text);
        if (trim($text) === '') {
            throw new HttpException(422, 'The automatic message is empty.');
        }

        return $this->create($business, $conversation, [
            'message' => $text,
            'message_type' => Message::TYPE_TEXT,
            'attachment_meta' => ['auto_reply' => $kind],
        ]);
    }

    /**
     * A reply or reaction to someone's status update (S4), quoting the update.
     *
     * @param  array<string, mixed>  $quote
     */
    public function sendStatusReply(User $sender, Conversation $conversation, string $text, array $quote): Message
    {
        $text = $this->cleanText($text);
        if (trim($text) === '') {
            throw new HttpException(422, 'Type a reply.');
        }

        return $this->create($sender, $conversation, [
            'message' => $text,
            'message_type' => Message::TYPE_TEXT,
            'attachment_meta' => ['status' => $quote],
        ]);
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
            // Nobody else could ever open it in "Message yourself"; one-to-one chats only.
            && ! $conversation->isSelf()
            && ! $conversation->hasMembers();

        if ($viewOnce) {
            $meta['view_once'] = true;
        } elseif (in_array($type, [Message::TYPE_IMAGE, Message::TYPE_VIDEO], true) && ! empty($data['album_id'])) {
            $meta['album'] = strtolower($data['album_id']);
        }

        $stored = $this->attachments->store($file, $type, $meta, $data['thumbnail'] ?? null, ($data['quality'] ?? null) === 'hd');

        $caption = $type === Message::TYPE_VOICE ? null : ($this->cleanText($data['message'] ?? '') ?: null);
        $stored['attachment_meta'] = ($stored['attachment_meta'] ?? []) + $this->mentionMeta($sender, $conversation, (string) $caption, $data['mentions'] ?? []);

        try {
            return $this->create($sender, $conversation, $stored + [
                'message' => $caption,
                'message_type' => $type,
                'reply_to_id' => $data['reply_to_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            $this->attachments->delete(new Message($stored));

            throw $e;
        }
    }

    /**
     * @mentions of people in a group (G4): only people in it, only names that appear in the text.
     *
     * @param  list<array{id: int|string, name: string}>  $mentions
     * @return array{mentions?: list<array{id: int, name: string}>, mention_ids?: list<int>}
     */
    private function mentionMeta(User $sender, Conversation $conversation, string $text, array $mentions): array
    {
        if (! $conversation->isGroup() || $mentions === [] || $text === '') {
            return [];
        }

        $memberIds = $conversation->activeMemberIds();
        $valid = collect($mentions)
            ->map(fn ($mention) => ['id' => (int) ($mention['id'] ?? 0), 'name' => trim((string) ($mention['name'] ?? ''))])
            ->filter(fn ($mention) => $mention['name'] !== ''
                && $mention['id'] !== (int) $sender->getKey()
                && in_array($mention['id'], $memberIds, true)
                && str_contains($text, '@'.$mention['name']))
            ->unique('id')
            ->values();

        return $valid->isEmpty() ? [] : ['mentions' => $valid->all(), 'mention_ids' => $valid->pluck('id')->all()];
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
            $forwarded[] = $this->createCopy($user, $conversation, $original, [
                'forward_count' => min(65535, (int) $original->forward_count + 1),
            ]);
        }

        return $forwarded;
    }

    /**
     * G9: a broadcast list message, copied into the one-to-one chat with a recipient.
     */
    public function copyForBroadcast(User $owner, Conversation $chat, Message $original): Message
    {
        return $this->createCopy($owner, $chat, $original, [
            'broadcast_message_id' => $original->getKey(),
            'forward_count' => (int) $original->forward_count,
        ]);
    }

    /**
     * An independent copy of a message in another chat (files are duplicated).
     *
     * @param  array<string, mixed>  $extra
     */
    private function createCopy(User $user, Conversation $conversation, Message $original, array $extra): Message
    {
        $attributes = $extra + [
            'message' => $original->message,
            'message_type' => $original->message_type,
            'link_preview_id' => $original->link_preview_id,
        ];

        if ($original->attachment) {
            $attributes += $this->attachments->duplicate($original);
        }

        // A copied poll starts again without votes.
        if ($original->message_type === Message::TYPE_CONTACT || $original->message_type === Message::TYPE_POLL) {
            $attributes['attachment_meta'] = $original->attachment_meta;
        }

        // A copied location is the last known point, never live.
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
            return $this->create($user, $conversation, $attributes);
        } catch (Throwable $e) {
            if (isset($attributes['attachment'])) {
                $this->attachments->delete(new Message($attributes));
            }

            throw $e;
        }
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
        // Someone else's group message: hidden only for this member.
        if ($message->isGroupMessage() && ! $message->isSentBy($user)) {
            DB::table('message_hides')->insertOrIgnore(['message_id' => $message->getKey(), 'user_id' => $user->getKey(), 'created_at' => now()]);
            $message->stars()->where('user_id', $user->getKey())->delete();
            broadcast(new MessageHidden($message->id, $message->conversation_id, $user->getKey()))->toOthers();

            return;
        }

        $column = $message->isSentBy($user) ? 'deleted_for_sender' : 'deleted_for_receiver';
        // A note to self is both sent and received by the same person.
        $message->forceFill($message->sender_id === $message->receiver_id
            ? ['deleted_for_sender' => true, 'deleted_for_receiver' => true]
            : [$column => true])->save();
        $message->stars()->where('user_id', $user->getKey())->delete();

        // Nobody can see it anymore: free the stored file.
        if (! $message->isGroupMessage() && $message->deleted_for_sender && $message->deleted_for_receiver && $message->attachment) {
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
        // Deleting a broadcast list message deletes it in every recipient's chat too (G9).
        if ($message->conversation?->isBroadcast()) {
            Message::query()->where('broadcast_message_id', $message->getKey())->where('deleted_for_everyone', false)
                ->get()->each(fn (Message $copy) => $this->deleteForEveryone($copy));
        }

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
                // Group messages have no single receiver (Phase 4).
                'receiver_id' => $conversation->hasMembers() ? null : $conversation->otherParticipantId($sender),
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
        if ($conversation->isGroup()) {
            $message->load('sender:id,name');
        }

        // Broadcast after commit; the sending browser tab is excluded (X-Socket-ID).
        broadcast(new MessageSent($message))->toOthers();

        // A broadcast list (G9): copy it into each recipient's chat after the response.
        if ($conversation->isBroadcast() && $message->message_type !== Message::TYPE_SYSTEM) {
            FanOutBroadcast::dispatchAfterResponse($message->getKey());
        }

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
        $groupCount = app(GroupReceiptService::class)->markDelivered($receiver, $ids);

        $pending = Message::query()
            ->where('receiver_id', $receiver->getKey())
            ->whereNull('delivered_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->limit(500)
            ->get(['id', 'conversation_id', 'sender_id']);

        if ($pending->isEmpty()) {
            return $groupCount;
        }

        $now = now();

        Message::query()
            ->whereIn('id', $pending->pluck('id'))
            ->whereNull('delivered_at')
            ->update(['delivered_at' => $now]);

        // Copies of broadcast list messages (G9) update the list's ticks.
        app(BroadcastService::class)->settle($pending->pluck('id')->map(fn ($id) => (int) $id)->all());

        $pending->groupBy('conversation_id')->each(function ($group, $conversationId) use ($now) {
            broadcast(new MessagesStatusUpdated(
                (int) $conversationId,
                (int) $group->first()->sender_id,
                $group->pluck('id')->map(fn ($id) => (int) $id)->all(),
                Message::STATUS_DELIVERED,
                $now->toIso8601String(),
            ));
        });

        return $pending->count() + $groupCount;
    }

    /**
     * Mark every unseen message the reader received in a conversation as seen.
     *
     * @return list<int> ids of messages that were updated
     */
    public function markSeen(Conversation $conversation, User $reader): array
    {
        // Channels (G11) have no receipts: the follower's place in the channel moves on.
        if ($conversation->isChannel()) {
            app(ChannelService::class)->markRead($conversation, $reader);
        }

        $ids = $conversation->isChannel() ? [] : ($conversation->isGroup()
            ? app(GroupReceiptService::class)->markSeen($conversation, $reader)
            : $conversation->messages()
                ->where('receiver_id', $reader->getKey())
                ->whereNull('seen_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all());

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

        // Group receipts were written above; phones still drop the chat's notification.
        if ($conversation->isGroup()) {
            if (app(PushService::class)->reachable($reader)) {
                SendReadPush::dispatchAfterResponse($reader->getKey(), $conversation->getKey());
            }

            return $ids;
        }

        DB::transaction(function () use ($ids, $now) {
            Message::query()->whereIn('id', $ids)->whereNull('delivered_at')->update(['delivered_at' => $now]);
            Message::query()->whereIn('id', $ids)->whereNull('seen_at')->update(['seen_at' => $now]);
        });
        app(BroadcastService::class)->settle($ids);

        // P3: the sender only hears "read" when both people share read receipts.
        if (! app(ReadReceiptService::class)->hiddenBetween((int) $reader->getKey(), $conversation->otherParticipantId($reader))) {
            broadcast(new MessagesStatusUpdated(
                $conversation->getKey(),
                $conversation->otherParticipantId($reader),
                $ids,
                Message::STATUS_SEEN,
                $now->toIso8601String(),
            ));
        }

        // Remove the chat's notification from the reader's phones.
        if (app(PushService::class)->reachable($reader)) {
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
