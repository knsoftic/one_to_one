<?php

namespace App\Services;

use App\Models\Community;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\Status;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Admin panel: every chat, message, group, channel, community and status update.
 * Opening chats is recorded in the audit log by the controllers.
 */
class AdminContentService
{
    public const CHAT_TYPES = [
        'direct' => 'One-to-one',
        'group' => 'Groups',
        'community' => 'Community announcements',
        'channel' => 'Channels',
        'broadcast' => 'Broadcast lists',
    ];

    public const PAGE = 50;

    /* ------------------------------------------------------------------ */
    /* Chats */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array{type?: ?string, q?: ?string, user?: ?int}  $filters
     */
    public function chats(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return Conversation::query()
            ->with(['userOne', 'userTwo', 'community', 'creator'])
            ->withCount(['messages', 'activeMembers'])
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => match ($type) {
                'community' => $q->where('is_announcement', true),
                'group' => $q->where('type', Conversation::TYPE_GROUP)->where('is_announcement', false),
                default => $q->where('type', $type),
            })
            ->when($filters['user'] ?? null, fn (Builder $q, int $userId) => $q->forUser($userId))
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function (Builder $q) use ($filters) {
                $term = '%'.addcslashes(trim((string) $filters['q']), '%_\\').'%';
                $people = User::query()->where(fn ($u) => $u->where('name', 'like', $term)->orWhere('username', 'like', $term)->orWhere('phone', 'like', $term))->select('id');

                $q->where(fn (Builder $w) => $w
                    ->where('name', 'like', $term)
                    ->orWhereIn('user_one_id', $people)
                    ->orWhereIn('user_two_id', $people)
                    ->orWhereHas('members', fn ($m) => $m->whereIn('user_id', $people)));
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function title(Conversation $chat): string
    {
        return match (true) {
            (bool) $chat->is_announcement => ($chat->community?->name ?? $chat->name ?? 'Community').' · announcements',
            $chat->type === Conversation::TYPE_DIRECT || ! $chat->type => $chat->isSelf()
                ? ($chat->userOne?->name ?? 'Deleted account').' (notes to self)'
                : ($chat->userOne?->name ?? 'Deleted account').' & '.($chat->userTwo?->name ?? 'Deleted account'),
            $chat->type === Conversation::TYPE_BROADCAST => ($chat->name ?: 'Broadcast list').' · '.($chat->creator?->name ?? 'owner'),
            default => $chat->name ?: 'Untitled',
        };
    }

    public function typeLabel(Conversation $chat): string
    {
        return match (true) {
            (bool) $chat->is_announcement => 'Community',
            $chat->type === Conversation::TYPE_GROUP => 'Group',
            $chat->type === Conversation::TYPE_CHANNEL => 'Channel',
            $chat->type === Conversation::TYPE_BROADCAST => 'Broadcast list',
            default => 'One-to-one',
        };
    }

    /**
     * A page of messages, oldest first, ending before $before.
     *
     * @return array{messages: Collection<int, Message>, older: ?int}
     */
    public function messages(Conversation $chat, ?int $before = null, int $limit = self::PAGE): array
    {
        $page = $chat->messages()
            ->with(['sender', 'replyTo.sender', 'reactions', 'pollVotes', 'linkPreview'])
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasOlder = $page->count() > $limit;
        $messages = $page->take($limit)->reverse()->values();

        return ['messages' => $messages, 'older' => $hasOlder ? (int) $messages->first()->id : null];
    }

    /**
     * People in a group, community, channel or broadcast list.
     *
     * @return Collection<int, ConversationMember>
     */
    public function members(Conversation $chat, int $limit = 300): Collection
    {
        return $chat->members()->with('user')->whereNull('left_at')
            ->orderByDesc('role')->orderBy('joined_at')->limit($limit)->get()
            ->filter(fn (ConversationMember $member) => $member->user !== null)->values();
    }

    /**
     * @return array{chats: int, messages_sent: int, groups: int, channels: int, communities: int}
     */
    public function userCounts(User $user): array
    {
        $memberships = ConversationMember::query()->where('user_id', $user->getKey())->whereNull('left_at')
            ->join('conversations', 'conversations.id', '=', 'conversation_members.conversation_id')
            ->selectRaw("SUM(conversations.type = 'group' AND conversations.is_announcement = 0) as `groups`")
            ->selectRaw("SUM(conversations.type = 'channel') as channels")
            ->selectRaw('SUM(conversations.is_announcement = 1) as communities')
            ->first();

        return [
            'chats' => Conversation::query()->forUser($user->getKey())->count(),
            'messages_sent' => $user->sentMessages()->count(),
            'groups' => (int) ($memberships->groups ?? 0),
            'channels' => (int) ($memberships->channels ?? 0),
            'communities' => (int) ($memberships->communities ?? 0),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Message search */
    /* ------------------------------------------------------------------ */

    public function searchMessages(string $term, int $perPage = 25): LengthAwarePaginator
    {
        $like = '%'.addcslashes(trim($term), '%_\\').'%';

        return Message::query()
            ->with(['sender', 'conversation.userOne', 'conversation.userTwo', 'conversation.community', 'conversation.creator'])
            ->where('deleted_for_everyone', false)
            ->where('message_type', '!=', Message::TYPE_SYSTEM)
            ->where(fn ($q) => $q->where('message', 'like', $like)->orWhere('attachment_name', 'like', $like))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /* ------------------------------------------------------------------ */
    /* Groups, channels, communities, status updates */
    /* ------------------------------------------------------------------ */

    public function groups(?string $search, string $state = 'active', int $perPage = 20): LengthAwarePaginator
    {
        return Conversation::query()
            ->where('type', Conversation::TYPE_GROUP)->where('is_announcement', false)
            ->with(['creator', 'community'])
            ->withCount(['activeMembers', 'messages'])
            ->when($state === 'active', fn ($q) => $q->whereNull('ended_at'))
            ->when($state === 'deleted', fn ($q) => $q->whereNotNull('ended_at'))
            ->when(filled($search), fn ($q) => $q->where('name', 'like', '%'.addcslashes((string) $search, '%_\\').'%'))
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function channels(?string $search, int $perPage = 20): LengthAwarePaginator
    {
        return Conversation::query()
            ->where('type', Conversation::TYPE_CHANNEL)
            ->with('creator')
            ->withCount(['activeMembers', 'messages'])
            ->when(filled($search), fn ($q) => $q->where('name', 'like', '%'.addcslashes((string) $search, '%_\\').'%'))
            ->orderByDesc('active_members_count')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function communities(?string $search, int $perPage = 20): LengthAwarePaginator
    {
        return Community::query()
            ->with(['creator', 'announcement' => fn ($q) => $q->withCount('activeMembers')])
            ->withCount('groups')
            ->when(filled($search), fn ($q) => $q->where('name', 'like', '%'.addcslashes((string) $search, '%_\\').'%'))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function statuses(?int $userId, int $perPage = 24): LengthAwarePaginator
    {
        return Status::query()->active()
            ->with('user')
            ->withCount('views')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
