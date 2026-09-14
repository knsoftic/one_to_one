<?php

namespace App\Services;

use App\Models\BlockedUser;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ConversationService
{
    public function __construct(private readonly ContactService $contacts) {}

    /**
     * Return the single conversation between two users, creating it when needed.
     * Passing the same user twice gives their "Message yourself" chat (C7).
     * Safe under concurrency thanks to the unique participants index.
     */
    public function findOrCreate(User $user, User $other): Conversation
    {
        [$one, $two] = Conversation::orderedPair($user, $other);

        return Conversation::query()->createOrFirst([
            'user_one_id' => $one,
            'user_two_id' => $two,
        ]);
    }

    /**
     * Recent conversations for the sidebar, newest activity first.
     *
     * Each conversation gets:
     *  - `latestMessage` relation: the newest message still visible to the user
     *  - `unread_count` attribute
     *  - `userOne` / `userTwo` relations
     *
     * @return Collection<int, Conversation>
     */
    public function recentFor(User $user, int $limit = 100): Collection
    {
        $userId = $user->getKey();

        $visible = fn (string $aggregate) => Message::query()
            ->selectRaw($aggregate)
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->visibleTo($userId);

        $conversations = Conversation::query()
            ->forUser($userId)
            ->leftJoin('chat_settings as my_settings', fn ($join) => $join
                ->on('my_settings.conversation_id', '=', 'conversations.id')
                ->where('my_settings.user_id', '=', $userId))
            ->select('conversations.*')
            ->selectSub($visible('MAX(messages.id)'), 'latest_message_id')
            ->selectSub($visible('MAX(messages.created_at)'), 'latest_message_at')
            ->addSelect(['my_settings.cleared_at as settings_cleared_at', 'my_settings.deleted_at as settings_deleted_at'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->unreadFor($userId)])
            // Unread messages that @mention me (G4).
            ->withCount(['messages as unread_mentions' => fn ($q) => $q->unreadFor($userId)->whereJsonContains('attachment_meta->mention_ids', (int) $userId)])
            ->with(['userOne', 'userTwo'])
            // Chats with messages, plus cleared chats that were not deleted (they stay in the list, empty).
            ->havingRaw('latest_message_id IS NOT NULL OR (settings_cleared_at IS NOT NULL AND settings_deleted_at IS NULL)')
            ->orderByRaw('COALESCE(latest_message_at, settings_cleared_at) DESC')
            ->orderByDesc('latest_message_id')
            ->limit($limit)
            ->get();

        return $this->attachGroupData($this->attachSettings($this->attachSavedNames(
            $this->attachBlockFlags($this->attachLatestMessages($conversations), $user),
            $user,
        ), $user), $user);
    }

    /**
     * Groups: the viewer's membership and how many people are in each group.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, Conversation>
     */
    private function attachGroupData(Collection $conversations, User $user): Collection
    {
        $groupIds = $conversations->filter(fn (Conversation $c) => $c->isGroup() || $c->isChannel())->modelKeys();

        if ($groupIds === []) {
            return $conversations;
        }

        $mine = ConversationMember::query()->where('user_id', $user->getKey())->whereIn('conversation_id', $groupIds)->get()->keyBy('conversation_id');
        $counts = ConversationMember::query()->whereIn('conversation_id', $groupIds)->whereNull('left_at')
            ->groupBy('conversation_id')->selectRaw('conversation_id, COUNT(*) as total, SUM(role = ?) as followers', [ConversationMember::ROLE_MEMBER])->get()->keyBy('conversation_id');

        return $conversations->each(function (Conversation $conversation) use ($mine, $counts) {
            if ($conversation->isGroup() || $conversation->isChannel()) {
                $conversation->setAttribute('my_membership', $mine->get($conversation->getKey()));
                $conversation->setAttribute('active_members_count', (int) ($counts->get($conversation->getKey())?->total ?? 0));
            }
            // Channels (G11) count their followers (not the admins).
            if ($conversation->isChannel()) {
                $conversation->setAttribute('followers_count', (int) ($counts->get($conversation->getKey())?->followers ?? 0));
            }
        });
    }

    /**
     * Add the viewer's own chat settings (pinned, muted, archived…) as `my_settings`.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, Conversation>
     */
    private function attachSettings(Collection $conversations, User $user): Collection
    {
        $settings = ChatSetting::query()
            ->where('user_id', $user->getKey())
            ->whereIn('conversation_id', $conversations->modelKeys())
            ->get()
            ->keyBy('conversation_id');

        return $conversations->each(function (Conversation $conversation) use ($settings) {
            $setting = $settings->get($conversation->getKey());

            $conversation->setAttribute('my_settings', ChatSetting::payload($setting) + [
                // A deleted chat stays out of the list until a new message arrives.
                'hidden' => $setting?->deleted_at !== null && $conversation->getAttribute('latest_message_id') === null,
            ]);
        });
    }

    /**
     * Load a single conversation with the same computed attributes as recentFor().
     */
    public function loadForUser(Conversation $conversation, User $user): Conversation
    {
        $userId = $user->getKey();

        $conversation->loadMissing(['userOne', 'userTwo']);
        $conversation->loadCount([
            'messages as unread_count' => fn ($q) => $q->unreadFor($userId),
            'messages as unread_mentions' => fn ($q) => $q->unreadFor($userId)->whereJsonContains('attachment_meta->mention_ids', (int) $userId),
        ]);

        $latest = $conversation->messages()->visibleTo($userId)->with('sender:id,name')->latest('id')->first();
        $conversation->setAttribute('latest_message_id', $latest?->id);
        $conversation->setRelation('latestMessage', $latest);

        $this->attachSettings($this->attachSavedNames($this->attachBlockFlags(new Collection([$conversation]), $user), $user), $user);

        // A group (or broadcast list) opened on its own includes its members (names, roles).
        if ($conversation->hasMembers()) {
            $conversation->setAttribute('with_group_members', true);
        }

        // Pinned messages are shown when a chat is opened (not in the chat list).
        $conversation->setAttribute('pinned_messages', app(PinService::class)->visibleFor($conversation, $user));

        return $conversation;
    }

    /**
     * Add `blocked_by_me` / `blocked_me` flags for the other participant.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, Conversation>
     */
    /**
     * Add the name the viewer saved for the other participant in their phone book.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, Conversation>
     */
    private function attachSavedNames(Collection $conversations, User $user): Collection
    {
        $names = $this->contacts->savedNames(
            $user,
            $conversations->map(fn (Conversation $c) => $c->otherParticipantId($user)),
        );

        return $conversations->each(fn (Conversation $conversation) => $conversation->setAttribute(
            'saved_name',
            $names[$conversation->otherParticipantId($user)] ?? null,
        ));
    }

    private function attachBlockFlags(Collection $conversations, User $user): Collection
    {
        if ($conversations->isEmpty()) {
            return $conversations;
        }

        $otherIds = $conversations->map(fn (Conversation $c) => $c->otherParticipantId($user))->unique()->values();

        $blockedByMe = BlockedUser::query()
            ->where('user_id', $user->getKey())
            ->whereIn('blocked_user_id', $otherIds)
            ->pluck('blocked_user_id')
            ->flip();

        $blockedMe = BlockedUser::query()
            ->where('blocked_user_id', $user->getKey())
            ->whereIn('user_id', $otherIds)
            ->pluck('user_id')
            ->flip();

        return $conversations->each(function (Conversation $conversation) use ($user, $blockedByMe, $blockedMe) {
            $otherId = $conversation->otherParticipantId($user);
            $conversation->setAttribute('blocked_by_me', $blockedByMe->has($otherId));
            $conversation->setAttribute('blocked_me', $blockedMe->has($otherId));
        });
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, Conversation>
     */
    private function attachLatestMessages(Collection $conversations): Collection
    {
        $messages = Message::query()
            ->whereIn('id', $conversations->pluck('latest_message_id')->filter())
            ->with('sender:id,name')
            ->get()
            ->keyBy('id');

        return $conversations->each(
            fn (Conversation $c) => $c->setRelation('latestMessage', $messages->get($c->latest_message_id))
        );
    }
}
