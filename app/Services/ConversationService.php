<?php

namespace App\Services;

use App\Models\BlockedUser;
use App\Models\ChatSetting;
use App\Models\Conversation;
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
            ->with(['userOne', 'userTwo'])
            // Chats with messages, plus cleared chats that were not deleted (they stay in the list, empty).
            ->havingRaw('latest_message_id IS NOT NULL OR (settings_cleared_at IS NOT NULL AND settings_deleted_at IS NULL)')
            ->orderByRaw('COALESCE(latest_message_at, settings_cleared_at) DESC')
            ->orderByDesc('latest_message_id')
            ->limit($limit)
            ->get();

        return $this->attachSettings($this->attachSavedNames(
            $this->attachBlockFlags($this->attachLatestMessages($conversations), $user),
            $user,
        ), $user);
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
        $conversation->loadCount(['messages as unread_count' => fn ($q) => $q->unreadFor($userId)]);

        $latest = $conversation->messages()->visibleTo($userId)->latest('id')->first();
        $conversation->setAttribute('latest_message_id', $latest?->id);
        $conversation->setRelation('latestMessage', $latest);

        $this->attachSettings($this->attachSavedNames($this->attachBlockFlags(new Collection([$conversation]), $user), $user), $user);

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
            ->get()
            ->keyBy('id');

        return $conversations->each(
            fn (Conversation $c) => $c->setRelation('latestMessage', $messages->get($c->latest_message_id))
        );
    }
}
