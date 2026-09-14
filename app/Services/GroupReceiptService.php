<?php

namespace App\Services;

use App\Events\MessagesStatusUpdated;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Delivered / read for group messages (Phase 4, G6).
 *
 * Every member gets a row in message_receipts when a message reaches their
 * device and when they read it. Once everyone who was in the group has
 * received / read a message, its delivered_at / seen_at are set, so the
 * sender's ✓✓ work as in one-to-one chats.
 */
class GroupReceiptService
{
    private const LIMIT = 1000;

    /**
     * Group messages that reached $user's device.
     *
     * @param  list<int>|null  $ids
     */
    public function markDelivered(User $user, ?array $ids = null): int
    {
        $userId = $user->getKey();

        $pending = Message::query()
            ->join('conversation_members as cm', fn ($join) => $join
                ->on('cm.conversation_id', '=', 'messages.conversation_id')
                ->where('cm.user_id', '=', $userId)
                ->whereNull('cm.left_at'))
            // Only groups: broadcast lists and channels (G11) have no receipts of their own.
            ->join('conversations as c', fn ($join) => $join->on('c.id', '=', 'messages.conversation_id')->where('c.type', '=', Conversation::TYPE_GROUP))
            ->whereNull('messages.receiver_id')
            ->where('messages.sender_id', '!=', $userId)
            ->where('messages.message_type', '!=', Message::TYPE_SYSTEM)
            ->whereColumn('messages.id', '>', 'cm.last_delivered_message_id')
            ->whereColumn('messages.id', '>=', 'cm.visible_from_message_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('messages.id', $ids))
            ->orderBy('messages.id')
            ->limit(self::LIMIT)
            ->get(['messages.id', 'messages.conversation_id']);

        if ($pending->isEmpty()) {
            return 0;
        }

        $now = now();
        DB::table('message_receipts')->insertOrIgnore(
            $pending->map(fn ($m) => ['message_id' => $m->id, 'user_id' => $userId, 'delivered_at' => $now])->all()
        );

        // Everything up to the newest delivered message counts as delivered (when no ids were given).
        if ($ids === null) {
            foreach ($pending->groupBy('conversation_id') as $conversationId => $group) {
                ConversationMember::query()
                    ->where('conversation_id', $conversationId)
                    ->where('user_id', $userId)
                    ->where('last_delivered_message_id', '<', $group->max('id'))
                    ->update(['last_delivered_message_id' => $group->max('id')]);
            }
        }

        $this->settle($pending->pluck('id')->all());

        return $pending->count();
    }

    /**
     * $reader opened the group: everything they can see is read.
     *
     * @return list<int> messages newly read
     */
    public function markSeen(Conversation $group, User $reader): array
    {
        $member = $group->memberFor($reader);
        if (! $member) {
            return [];
        }

        $newest = (int) $group->messages()->visibleTo($reader)->max('id');
        if ($newest <= $member->last_read_message_id) {
            return [];
        }

        $ids = $group->messages()
            ->whereNull('receiver_id')
            ->where('sender_id', '!=', $reader->getKey())
            ->where('message_type', '!=', Message::TYPE_SYSTEM)
            ->where('id', '>', $member->last_read_message_id)
            ->where('id', '<=', $newest)
            ->where('id', '>=', $member->visible_from_message_id)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $now = now();

        DB::transaction(function () use ($ids, $reader, $now, $member, $newest) {
            if ($ids !== []) {
                DB::table('message_receipts')->insertOrIgnore(
                    array_map(fn ($id) => ['message_id' => $id, 'user_id' => $reader->getKey(), 'delivered_at' => $now, 'seen_at' => $now], $ids)
                );
                DB::table('message_receipts')->whereIn('message_id', $ids)->where('user_id', $reader->getKey())->whereNull('delivered_at')->update(['delivered_at' => $now]);
                DB::table('message_receipts')->whereIn('message_id', $ids)->where('user_id', $reader->getKey())->whereNull('seen_at')->update(['seen_at' => $now]);
            }

            $member->forceFill([
                'last_read_message_id' => $newest,
                'last_delivered_message_id' => max($member->last_delivered_message_id, $newest),
            ])->save();
        });

        $this->settle($ids);

        return $ids;
    }

    /**
     * Set delivered_at / seen_at on messages everyone has received / read, and tell their senders.
     *
     * @param  list<int>  $ids
     */
    public function settle(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $messages = Message::query()->whereKey($ids)->whereNull('receiver_id')
            ->get(['id', 'conversation_id', 'sender_id', 'delivered_at', 'seen_at']);

        $counts = DB::table('message_receipts')
            ->whereIn('message_id', $ids)
            ->groupBy('message_id')
            ->selectRaw('message_id, COUNT(delivered_at) as delivered, COUNT(seen_at) as seen')
            ->get()
            ->keyBy('message_id');

        $receiptUsers = DB::table('message_receipts')->whereIn('message_id', $ids)->get(['message_id', 'user_id'])
            ->groupBy('message_id')
            ->map(fn (Collection $rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        $now = now();
        $changes = [];

        foreach ($messages->groupBy('conversation_id') as $conversationId => $group) {
            $members = ConversationMember::query()->where('conversation_id', $conversationId)->get();

            foreach ($group as $message) {
                $withReceipt = $receiptUsers->get($message->id, []);
                // People who were in the group then, except those who left without receiving it.
                $recipients = $members->filter(fn (ConversationMember $m) => (int) $m->user_id !== (int) $message->sender_id
                    && $m->couldSee((int) $message->id)
                    && ($m->isActive() || in_array((int) $m->user_id, $withReceipt, true)))->count();

                if ($recipients === 0) {
                    continue;
                }

                $count = $counts->get($message->id);
                $key = $conversationId.':'.$message->sender_id;

                if (! $message->delivered_at && $count && $count->delivered >= $recipients) {
                    $changes[$key][Message::STATUS_DELIVERED][] = (int) $message->id;
                }
                if (! $message->seen_at && $count && $count->seen >= $recipients) {
                    $changes[$key][Message::STATUS_SEEN][] = (int) $message->id;
                }
            }
        }

        foreach ($changes as $key => $statuses) {
            [$conversationId, $senderId] = array_map('intval', explode(':', $key));

            foreach ($statuses as $status => $messageIds) {
                Message::query()->whereKey($messageIds)->whereNull('delivered_at')->update(['delivered_at' => $now]);
                if ($status === Message::STATUS_SEEN) {
                    Message::query()->whereKey($messageIds)->whereNull('seen_at')->update(['seen_at' => $now]);
                }

                broadcast(new MessagesStatusUpdated($conversationId, $senderId, $messageIds, $status, $now->toIso8601String()));
            }
        }
    }

    /**
     * "Read by" / "Delivered to" of one group message (G6).
     *
     * @return Collection<int, array{user_id: int, delivered_at: ?string, seen_at: ?string}>
     */
    public function receiptsFor(Message $message): Collection
    {
        $receipts = DB::table('message_receipts')->where('message_id', $message->getKey())->get()->keyBy('user_id');

        return ConversationMember::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', '!=', $message->sender_id)
            ->get()
            ->filter(fn (ConversationMember $m) => $m->couldSee((int) $message->getKey()) && ($m->isActive() || $receipts->has($m->user_id)))
            ->map(fn (ConversationMember $m) => [
                'user_id' => (int) $m->user_id,
                'delivered_at' => $receipts->get($m->user_id)?->delivered_at ? Carbon::parse($receipts->get($m->user_id)->delivered_at)->toIso8601String() : null,
                'seen_at' => $receipts->get($m->user_id)?->seen_at ? Carbon::parse($receipts->get($m->user_id)->seen_at)->toIso8601String() : null,
            ])
            ->values();
    }
}
