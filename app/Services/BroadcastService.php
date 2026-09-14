<?php

namespace App\Services;

use App\Events\GroupUpdated;
use App\Events\MessagesStatusUpdated;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * G9 — Broadcast lists: one message to many people, each in their own chat
 * (they don't see who else got it, and replies come back one-to-one).
 */
class BroadcastService
{
    public const MIN_RECIPIENTS = 2;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ContactService $contacts,
    ) {}

    public function maxRecipients(): int
    {
        return max(self::MIN_RECIPIENTS, (int) config('chat.groups.max_broadcast_recipients', 256));
    }

    /**
     * @param  list<int>  $userIds
     */
    public function create(User $owner, ?string $name, array $userIds): Conversation
    {
        $recipients = $this->recipients($owner, $userIds);

        $list = DB::transaction(function () use ($owner, $name, $recipients) {
            $list = Conversation::create([
                'type' => Conversation::TYPE_BROADCAST,
                'name' => $this->cleanName($name),
                'created_by' => $owner->getKey(),
            ]);

            $list->members()->create([
                'user_id' => $owner->getKey(),
                'role' => ConversationMember::ROLE_ADMIN,
                'joined_at' => now(),
            ]);

            $this->syncRecipients($list, $recipients);

            return $list;
        });

        return $list->fresh();
    }

    /**
     * @param  list<int>|null  $userIds
     */
    public function update(Conversation $list, User $owner, bool $renaming, ?string $name, ?array $userIds): Conversation
    {
        $this->ensureOwner($list, $owner);

        if ($renaming) {
            $list->forceFill(['name' => $this->cleanName($name)])->save();
        }

        if ($userIds !== null) {
            $this->syncRecipients($list, $this->recipients($owner, $userIds));
        }

        broadcast(new GroupUpdated($list));

        return $list;
    }

    public function delete(Conversation $list, User $owner): void
    {
        $this->ensureOwner($list, $owner);

        // Copies already sent stay in each chat; the list and its own messages go.
        broadcast(new GroupUpdated($list));
        $list->delete();
    }

    /**
     * Copy a message sent to the list into the one-to-one chat with each recipient.
     *
     * @return int copies made
     */
    public function fanOut(Message $original): int
    {
        $list = $original->conversation;
        $owner = $original->sender;

        if (! $list?->isBroadcast() || ! $owner || $original->deleted_for_everyone) {
            return 0;
        }

        $messages = app(MessageService::class);
        $copies = 0;

        // Never twice for the same person (safe to run again).
        $alreadySent = Message::query()->where('broadcast_message_id', $original->getKey())->pluck('receiver_id')->map(fn ($id) => (int) $id)->all();

        foreach ($list->broadcastRecipients()->get() as $recipient) {
            if (in_array((int) $recipient->getKey(), $alreadySent, true) || ! $recipient->isActive() || $owner->hasBlockWith($recipient->getKey())) {
                continue;
            }

            try {
                $chat = $this->conversations->findOrCreate($owner, $recipient);
                $messages->copyForBroadcast($owner, $chat, $original);
                $copies++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $copies;
    }

    /**
     * Copies were delivered / read: update the list's message when every copy is.
     *
     * @param  list<int>  $copyIds
     */
    public function settle(array $copyIds): void
    {
        $originalIds = Message::query()->whereKey($copyIds)->whereNotNull('broadcast_message_id')->distinct()->pluck('broadcast_message_id');
        if ($originalIds->isEmpty()) {
            return;
        }

        $now = now();
        $originals = Message::query()->whereKey($originalIds)->get(['id', 'conversation_id', 'sender_id', 'delivered_at', 'seen_at']);
        // A copy counts as read only when its recipient shares read receipts (P3).
        $stats = Message::query()->whereIn('broadcast_message_id', $originalIds)
            ->join('users as recipients', 'recipients.id', '=', 'messages.receiver_id')
            ->groupBy('broadcast_message_id')
            ->selectRaw('broadcast_message_id, COUNT(*) as total, COUNT(messages.delivered_at) as delivered, SUM(messages.seen_at IS NOT NULL AND recipients.read_receipts = 1) as seen')
            ->get()
            ->keyBy('broadcast_message_id');
        $receipts = app(ReadReceiptService::class);

        foreach ($originals as $original) {
            $stat = $stats->get($original->id);
            if (! $stat || (int) $stat->total === 0) {
                continue;
            }

            if (! $original->delivered_at && $stat->delivered >= $stat->total) {
                $original->forceFill(['delivered_at' => $now])->save();
                broadcast(new MessagesStatusUpdated((int) $original->conversation_id, (int) $original->sender_id, [(int) $original->id], Message::STATUS_DELIVERED, $now->toIso8601String()));
            }
            if (! $original->seen_at && (int) $stat->seen >= (int) $stat->total && ! $receipts->hiddenBetween((int) $original->sender_id, 0)) {
                $original->forceFill(['seen_at' => $now, 'delivered_at' => $original->delivered_at ?? $now])->save();
                broadcast(new MessagesStatusUpdated((int) $original->conversation_id, (int) $original->sender_id, [(int) $original->id], Message::STATUS_SEEN, $now->toIso8601String()));
            }
        }
    }

    /**
     * "Read by" / "Delivered to" of a list message, from its copies.
     *
     * @return Collection<int, array{user_id: int, delivered_at: ?string, seen_at: ?string}>
     */
    public function receiptsFor(Message $original): Collection
    {
        $receipts = app(ReadReceiptService::class);

        return Message::query()->where('broadcast_message_id', $original->getKey())
            ->get(['receiver_id', 'delivered_at', 'seen_at'])
            ->map(fn (Message $copy) => [
                'user_id' => (int) $copy->receiver_id,
                'delivered_at' => $copy->delivered_at?->toIso8601String(),
                // Nobody sees when someone read it if either of them turned read receipts off (P3).
                'seen_at' => $receipts->hiddenBetween((int) $original->sender_id, (int) $copy->receiver_id) ? null : $copy->seen_at?->toIso8601String(),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Conversation $list, User $viewer, bool $withRecipients, ?Request $request = null): array
    {
        $recipients = $list->broadcastRecipients()->orderBy('name')->get();

        $payload = [
            'name' => $list->name,
            'recipient_count' => $recipients->count(),
            'is_owner' => (int) $list->created_by === (int) $viewer->getKey(),
            'max_recipients' => $this->maxRecipients(),
        ];

        if ($withRecipients) {
            $request ??= request();
            $saved = $this->contacts->savedNames($viewer, $recipients->modelKeys());
            $payload['recipients'] = $recipients
                ->map(fn (User $user) => (new UserResource($user))->resolve($request) + ['saved_name' => $saved[$user->id] ?? null])
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    private function recipients(User $owner, array $userIds): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($id) => $id > 0 && $id !== (int) $owner->getKey())));

        $people = User::query()->whereKey($ids)->active()->get()
            ->reject(fn (User $user) => $owner->hasBlockWith($user->getKey()))
            ->values();

        if ($people->count() < self::MIN_RECIPIENTS) {
            throw new HttpException(422, 'A broadcast list needs at least '.self::MIN_RECIPIENTS.' people.');
        }

        if ($people->count() > $this->maxRecipients()) {
            throw new HttpException(422, "A broadcast list can have up to {$this->maxRecipients()} people.");
        }

        return $people;
    }

    /**
     * @param  Collection<int, User>  $people
     */
    private function syncRecipients(Conversation $list, Collection $people): void
    {
        DB::table('broadcast_recipients')->where('conversation_id', $list->getKey())->whereNotIn('user_id', $people->modelKeys())->delete();
        DB::table('broadcast_recipients')->insertOrIgnore(
            $people->map(fn (User $user) => ['conversation_id' => $list->getKey(), 'user_id' => $user->getKey(), 'created_at' => now()])->all()
        );
    }

    private function ensureOwner(Conversation $list, User $user): void
    {
        abort_unless($list->isBroadcast() && (int) $list->created_by === (int) $user->getKey(), 404);
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

        return $name === '' ? null : mb_substr($name, 0, GroupService::MAX_NAME);
    }
}
