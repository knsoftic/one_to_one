<?php

namespace App\Services;

use App\Events\GroupUpdated;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 4 — Group chats: create, members, admins, info, settings, leave,
 * delete and invite links. Every change leaves a notice in the chat, like WhatsApp.
 */
class GroupService
{
    public const MAX_NAME = 100;

    public const MAX_DESCRIPTION = 512;

    public function __construct(
        private readonly MessageService $messages,
        private readonly ImageService $images,
        private readonly ContactService $contacts,
        private readonly LimitService $limits,
    ) {}

    /** Most people in a group. A paid plan (Y2) of the group's creator raises it, never lowers it. */
    public function maxMembers(?User $for = null): int
    {
        $base = max(3, (int) config('chat.groups.max_members', 256));

        return $for ? max($base, $this->limits->groupMembers($for)) : $base;
    }

    /* ------------------------------------------------------------------ */
    /* G1 — Create, add people, info */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<int>  $memberIds  people added besides the creator
     */
    public function create(User $creator, string $name, array $memberIds, ?string $description = null, ?UploadedFile $avatar = null, bool $allowEmpty = false): Conversation
    {
        $name = $this->cleanName($name);
        $people = $this->addablePeople($creator, $memberIds);

        if ($people->isEmpty() && ! $allowEmpty) {
            throw new HttpException(422, 'Add at least one person to the group.');
        }

        if ($people->count() + 1 > $this->maxMembers($creator)) {
            throw new HttpException(422, "A group can have up to {$this->maxMembers($creator)} people.");
        }

        $avatarPath = $avatar ? $this->storeAvatar($avatar) : null;

        $group = DB::transaction(function () use ($creator, $name, $description, $avatarPath, $people) {
            $group = Conversation::create([
                'type' => Conversation::TYPE_GROUP,
                'name' => $name,
                'description' => $this->cleanDescription($description),
                'avatar' => $avatarPath,
                'created_by' => $creator->getKey(),
            ]);

            $now = now();
            $group->members()->create([
                'user_id' => $creator->getKey(),
                'role' => ConversationMember::ROLE_ADMIN,
                'joined_at' => $now,
            ]);

            foreach ($people as $person) {
                $group->members()->create([
                    'user_id' => $person->getKey(),
                    'role' => ConversationMember::ROLE_MEMBER,
                    'added_by' => $creator->getKey(),
                    'joined_at' => $now,
                ]);
            }

            return $group;
        });

        $this->notice($creator, $group, 'group_created', ['name' => $name, 'users' => $this->names($people)]);

        return $group->fresh();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User> the people added
     */
    public function addMembers(Conversation $group, User $by, array $userIds): Collection
    {
        $this->ensureCanChangeInfo($group, $by, 'Only admins can add people to this group.');

        $current = $group->activeMembers()->pluck('user_id')->map(fn ($id) => (int) $id);
        $people = $this->addablePeople($by, array_values(array_diff(array_map('intval', $userIds), $current->all())));

        if ($people->isEmpty()) {
            throw new HttpException(422, 'These people are already in the group or cannot be added.');
        }

        // The creator's plan sets the group's size, whoever adds people.
        $max = $this->maxMembers($group->creator);
        if ($current->count() + $people->count() > $max) {
            throw new HttpException(422, "A group can have up to {$max} people.");
        }

        $this->join($group, $people, $by);
        // Community announcements (G10) never name members to each other.
        if ($group->is_announcement) {
            broadcast(new GroupUpdated($group));
        } else {
            $this->notice($by, $group, 'members_added', ['users' => $this->names($people)]);
        }

        return $people;
    }

    /**
     * @param  array{name?: ?string, description?: ?string}  $changes
     */
    public function updateInfo(Conversation $group, User $by, array $changes, ?UploadedFile $avatar = null, bool $removeAvatar = false): Conversation
    {
        $this->ensureCanChangeInfo($group, $by, 'Only admins can edit this group\'s info.');

        if (array_key_exists('name', $changes) && $changes['name'] !== null) {
            $name = $this->cleanName($changes['name']);
            if ($name !== $group->name) {
                $group->forceFill(['name' => $name])->save();
                $this->notice($by, $group, 'name_changed', ['name' => $name]);
            }
        }

        if (array_key_exists('description', $changes)) {
            $description = $this->cleanDescription($changes['description']);
            if ($description !== $group->description) {
                $group->forceFill(['description' => $description])->save();
                $this->notice($by, $group, 'description_changed', []);
            }
        }

        if ($avatar) {
            $group->forceFill(['avatar' => $this->storeAvatar($avatar, $group->avatar)])->save();
            $this->notice($by, $group, 'avatar_changed', []);
        } elseif ($removeAvatar && $group->avatar) {
            $this->images->deleteAvatar($group->avatar);
            $group->forceFill(['avatar' => null])->save();
            $this->notice($by, $group, 'avatar_removed', []);
        }

        broadcast(new GroupUpdated($group));

        return $group;
    }

    /* ------------------------------------------------------------------ */
    /* G2 — Admins and removing people */
    /* ------------------------------------------------------------------ */

    public function setAdmin(Conversation $group, User $by, User $target, bool $admin): ConversationMember
    {
        $this->ensureAdmin($group, $by);
        $member = $this->activeMember($group, $target);

        if (! $admin && (int) $group->created_by === (int) $target->getKey() && ! $by->is($target)) {
            throw new HttpException(403, 'The group creator cannot be dismissed as admin.');
        }

        if (! $admin && $by->is($target) && $group->activeMembers()->where('role', ConversationMember::ROLE_ADMIN)->count() <= 1) {
            throw new HttpException(422, 'Make someone else an admin first.');
        }

        $member->forceFill(['role' => $admin ? ConversationMember::ROLE_ADMIN : ConversationMember::ROLE_MEMBER])->save();
        broadcast(new GroupUpdated($group));

        return $member;
    }

    public function removeMember(Conversation $group, User $by, User $target): void
    {
        $this->ensureAdmin($group, $by);

        // Removing someone from a community's announcements removes them from the community (G10).
        if ($group->is_announcement && $group->community) {
            app(CommunityService::class)->removeMember($group->community, $by, $target);

            return;
        }

        if ($by->is($target)) {
            throw new HttpException(422, 'Use "Exit group" to leave the group.');
        }

        if ((int) $group->created_by === (int) $target->getKey()) {
            throw new HttpException(403, 'The group creator cannot be removed.');
        }

        $member = $this->activeMember($group, $target);
        $notice = $this->notice($by, $group, 'member_removed', ['users' => $this->names(collect([$target]))], broadcast: false);
        $this->markLeft($member, $notice);

        broadcast(new GroupUpdated($group, [(int) $target->getKey()]));
    }

    /* ------------------------------------------------------------------ */
    /* G5 — Settings */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array{only_admins_send?: bool, only_admins_edit?: bool}  $settings
     */
    public function updateSettings(Conversation $group, User $by, array $settings): Conversation
    {
        $this->ensureAdmin($group, $by);

        if ($group->is_announcement && array_key_exists('only_admins_send', $settings) && ! $settings['only_admins_send']) {
            throw new HttpException(422, "Only admins send messages in a community's announcements.");
        }

        foreach (['only_admins_send', 'only_admins_edit'] as $key) {
            if (! array_key_exists($key, $settings) || (bool) $settings[$key] === (bool) $group->{$key}) {
                continue;
            }

            $group->forceFill([$key => (bool) $settings[$key]])->save();
            $this->notice($by, $group, 'settings_changed', [$key => (bool) $settings[$key]]);
        }

        broadcast(new GroupUpdated($group));

        return $group;
    }

    /* ------------------------------------------------------------------ */
    /* G8 — Leave and delete */
    /* ------------------------------------------------------------------ */

    public function leave(Conversation $group, User $user): void
    {
        // Leaving a community's announcements is leaving the community (G10).
        if ($group->is_announcement && $group->community) {
            app(CommunityService::class)->leave($group->community, $user);

            return;
        }

        $this->leaveGroup($group, $user);
    }

    /**
     * Leave one group (used by communities for each of their groups).
     */
    public function leaveGroup(Conversation $group, User $user): void
    {
        $member = $this->activeMember($group, $user);

        // Community announcements (G10): members leave quietly, nobody else is told who.
        $until = $group->is_announcement
            ? (int) $group->messages()->max('id')
            : $this->notice($user, $group, 'member_left', [], broadcast: false);
        $wasAdmin = $member->isAdmin();
        $this->markLeft($member, $until);

        $remaining = $group->activeMembers()->orderBy('joined_at')->orderBy('id')->get();

        if ($remaining->isEmpty()) {
            $group->forceFill(['ended_at' => now(), 'invite_token' => null])->save();
        } elseif ($wasAdmin && ! $remaining->contains(fn (ConversationMember $m) => $m->role === ConversationMember::ROLE_ADMIN)) {
            // Like WhatsApp: when the last admin leaves, the longest-standing member becomes admin.
            $remaining->first()->forceFill(['role' => ConversationMember::ROLE_ADMIN])->save();
        }

        broadcast(new GroupUpdated($group, [(int) $user->getKey()]));
    }

    /**
     * Delete the group for everyone: all people are removed and nobody can send any more.
     */
    public function end(Conversation $group, User $by, bool $forCommunity = false): void
    {
        $this->ensureAdmin($group, $by);

        if ($group->is_announcement && ! $forCommunity) {
            throw new HttpException(422, 'Delete the community to delete its announcements.');
        }

        $this->close($group, $by, ['id' => $by->getKey(), 'name' => $by->name]);
    }

    /**
     * The app's administrators delete a group for everyone (admin panel).
     */
    public function endByModerator(Conversation $group, User $admin): void
    {
        abort_unless($group->isGroup() && $group->ended_at === null, 404);

        $this->close($group, $admin, self::MODERATOR);
    }

    /** Who a notice names when the app's administrators act. */
    public const MODERATOR = ['id' => 0, 'name' => 'An administrator'];

    /**
     * @param  array{id: int, name: string}  $actor
     */
    private function close(Conversation $group, User $by, array $actor): void
    {
        $notice = $this->messages->systemNotice($by, $group, ['event' => 'group_ended', 'actor' => $actor]);
        $members = $group->activeMembers()->get();

        DB::transaction(function () use ($group, $members, $notice) {
            foreach ($members as $member) {
                $this->markLeft($member, $notice);
            }
            $group->forceFill(['ended_at' => now(), 'invite_token' => null])->save();
        });

        broadcast(new GroupUpdated($group, $members->pluck('user_id')->map(fn ($id) => (int) $id)->all()));
    }

    /* ------------------------------------------------------------------ */
    /* G3 — Invite link */
    /* ------------------------------------------------------------------ */

    public function inviteLink(Conversation $group, User $by, bool $reset = false): string
    {
        $this->ensureAdmin($group, $by);

        if ($group->is_announcement && $group->community) {
            return app(CommunityService::class)->inviteLink($group->community, $by, $reset);
        }

        if ($reset || ! $group->invite_token) {
            $group->forceFill(['invite_token' => Str::random(22)])->save();
        }

        return route('groups.join.show', $group->invite_token);
    }

    public function findByInvite(string $token): ?Conversation
    {
        return Conversation::query()
            ->where('type', Conversation::TYPE_GROUP)
            ->where('invite_token', $token)
            ->whereNull('ended_at')
            ->first();
    }

    public function joinWithLink(string $token, User $user): Conversation
    {
        $group = $this->findByInvite($token);

        if (! $group) {
            throw new HttpException(410, 'This invite link is no longer valid.');
        }

        if ($group->isActiveMember($user)) {
            return $group;
        }

        if ($group->activeMembers()->count() >= $this->maxMembers($group->creator)) {
            throw new HttpException(422, 'This group is full.');
        }

        $this->join($group, collect([$user]), null);
        $this->notice($user, $group, 'member_joined_link', []);

        return $group;
    }

    /**
     * What someone sees before joining with a link.
     *
     * @return array<string, mixed>
     */
    public function invitePreview(Conversation $group, User $viewer): array
    {
        return [
            'token' => $group->invite_token,
            'name' => $group->name,
            'description' => $group->description,
            'avatar_url' => $group->groupAvatarUrl(),
            'initials' => $group->groupInitials(),
            'avatar_hue' => $group->groupHue(),
            'member_count' => $group->activeMembers()->count(),
            'is_member' => $group->isActiveMember($viewer),
            'conversation_id' => $group->getKey(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Payload */
    /* ------------------------------------------------------------------ */

    /**
     * Group details for the chat payload. Members are included when $withMembers.
     *
     * @return array<string, mixed>
     */
    public function payload(Conversation $group, User $viewer, bool $withMembers, ?Request $request = null): array
    {
        $mine = $group->getAttribute('my_membership') ?? $group->memberFor($viewer);
        $active = (bool) $mine?->isActive() && $group->ended_at === null;
        $admin = $active && $mine->role === ConversationMember::ROLE_ADMIN;
        $hideMembers = (bool) $group->is_announcement && ! $admin;

        $payload = [
            'name' => $group->name,
            'description' => $group->description,
            'avatar_url' => $group->groupAvatarUrl(),
            'initials' => $group->groupInitials(),
            'avatar_hue' => $group->groupHue(),
            'created_by' => $group->created_by,
            'created_at' => $group->created_at?->toIso8601String(),
            'only_admins_send' => (bool) $group->only_admins_send,
            'only_admins_edit' => (bool) $group->only_admins_edit,
            'ended' => $group->ended_at !== null,
            'member_count' => (int) ($group->getAttribute('active_members_count') ?? $group->activeMembers()->count()),
            'is_member' => $active,
            // Community announcements (G10): only admins see everyone; members see the admins.
            'members_hidden' => $hideMembers,
            'my_role' => $active ? $mine->role : null,
            'can_send' => $active && (! $group->only_admins_send || $admin),
            'can_edit_info' => $active && (! $group->only_admins_edit || $admin),
            'max_members' => $this->maxMembers($group->creator),
            // Part of a community (G10).
            'community' => $group->community_id ? [
                'id' => (int) $group->community_id,
                'name' => $group->community?->name,
                'is_announcement' => (bool) $group->is_announcement,
            ] : null,
        ];

        if ($withMembers) {
            $request ??= request();
            $members = $group->members()->with('user')->orderByDesc('role')->orderBy('joined_at')->get()
                ->filter(fn (ConversationMember $member) => $member->user !== null)
                ->filter(fn (ConversationMember $member) => (int) $member->user_id === (int) $viewer->getKey()
                    // Someone who left or was removed no longer sees who is in the group.
                    || ($active && (! $hideMembers || ($member->isActive() && $member->role === ConversationMember::ROLE_ADMIN))));
            $saved = $this->contacts->savedNames($viewer, $members->pluck('user_id')->all());

            $payload['members'] = $members
                ->sortBy(fn (ConversationMember $m) => [(int) $m->user_id !== (int) $viewer->getKey(), $m->role !== ConversationMember::ROLE_ADMIN, $m->joined_at?->getTimestamp()])
                ->map(fn (ConversationMember $member) => [
                    'user' => (new UserResource($member->user))->resolve($request) + ['saved_name' => $saved[$member->user_id] ?? null],
                    'role' => $member->role,
                    'is_creator' => (int) $member->user_id === (int) $group->created_by,
                    'active' => $member->isActive(),
                    'joined_at' => $member->joined_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return $payload;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Add people to a group, with a notice (used by communities).
     *
     * @param  Collection<int, User>  $people
     */
    public function addPeople(Conversation $group, Collection $people, ?User $by, ?string $event = null, ?User $actor = null): void
    {
        $current = $group->activeMembers()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $people = $people->reject(fn (User $user) => in_array((int) $user->getKey(), $current, true))->values();

        if ($people->isEmpty()) {
            return;
        }

        $this->join($group, $people, $by);

        if ($event !== null) {
            $this->notice($actor ?? $by ?? $people->first(), $group, $event, $event === 'members_added' ? ['users' => $this->names($people)] : []);
        } else {
            broadcast(new GroupUpdated($group));
        }
    }

    /**
     * Add people (or bring back people who left) from the next message on.
     *
     * @param  Collection<int, User>  $people
     */
    private function join(Conversation $group, Collection $people, ?User $by): void
    {
        DB::transaction(function () use ($group, $people, $by) {
            $from = (int) $group->messages()->max('id') + 1;
            $now = now();

            foreach ($people as $person) {
                $group->members()->updateOrCreate(['user_id' => $person->getKey()], [
                    'role' => ConversationMember::ROLE_MEMBER,
                    'added_by' => $by?->getKey(),
                    'joined_at' => $now,
                    'left_at' => null,
                    'visible_from_message_id' => $from,
                    'visible_until_message_id' => null,
                    'last_read_message_id' => $from - 1,
                    'last_delivered_message_id' => $from - 1,
                ]);
            }
        });

        $group->unsetRelation('members');

        // People in a community's group are in the community (G10).
        if ($group->community_id && ! $group->is_announcement && $group->community) {
            app(CommunityService::class)->ensureMembers($group->community, $people);
        }
    }

    /**
     * @param  Message|int  $until  the notice about it, or the last message they may see
     */
    private function markLeft(ConversationMember $member, Message|int $until): void
    {
        $member->forceFill([
            'left_at' => now(),
            'role' => ConversationMember::ROLE_MEMBER,
            'visible_until_message_id' => $until instanceof Message ? $until->getKey() : $until,
        ])->save();

        $member->conversation?->unsetRelation('members');

        // Messages this person never received may now count as delivered / read by everyone left (G6).
        $recent = Message::query()->where('conversation_id', $member->conversation_id)->whereNull('receiver_id')
            ->where(fn ($q) => $q->whereNull('seen_at')->orWhereNull('delivered_at'))
            ->where('message_type', '!=', Message::TYPE_SYSTEM)
            ->orderByDesc('id')->limit(200)->pluck('id')->map(fn ($id) => (int) $id)->all();
        app(GroupReceiptService::class)->settle($recent);
    }

    /**
     * People $by may add: active accounts, not blocked either way.
     *
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function addablePeople(User $by, array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0 && $id !== (int) $by->getKey())));

        if ($ids === []) {
            return collect();
        }

        return User::query()->whereKey($ids)->active()->get()
            ->reject(fn (User $user) => $by->hasBlockWith($user->getKey()))
            ->values();
    }

    private function activeMember(Conversation $group, User $user): ConversationMember
    {
        $member = $group->memberFor($user);

        if (! $member?->isActive()) {
            throw new HttpException(422, "{$user->name} is not in this group.");
        }

        return $member;
    }

    private function ensureAdmin(Conversation $group, User $user): void
    {
        $this->ensureOpen($group, $user);

        if (! $group->isAdmin($user)) {
            throw new HttpException(403, 'Only group admins can do this.');
        }
    }

    private function ensureCanChangeInfo(Conversation $group, User $user, string $adminsOnlyMessage): void
    {
        $this->ensureOpen($group, $user);

        if ($group->only_admins_edit && ! $group->isAdmin($user)) {
            throw new HttpException(403, $adminsOnlyMessage);
        }
    }

    private function ensureOpen(Conversation $group, User $user): void
    {
        if ($group->ended_at !== null || ! $group->isActiveMember($user)) {
            throw new HttpException(403, 'You are no longer a member of this group.');
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function notice(User $actor, Conversation $group, string $event, array $meta, bool $broadcast = true): Message
    {
        $notice = $this->messages->systemNotice($actor, $group, ['event' => $event, 'actor' => ['id' => $actor->getKey(), 'name' => $actor->name]] + $meta);

        if ($broadcast) {
            broadcast(new GroupUpdated($group));
        }

        return $notice;
    }

    /**
     * @param  Collection<int, User>  $people
     * @return list<array{id: int, name: string}>
     */
    private function names(Collection $people): array
    {
        return $people->map(fn (User $user) => ['id' => (int) $user->getKey(), 'name' => $user->name])->values()->all();
    }

    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $this->messages->cleanText($name)));

        if ($name === '') {
            throw new HttpException(422, 'Give the group a name.');
        }

        return mb_substr($name, 0, self::MAX_NAME);
    }

    private function cleanDescription(?string $description): ?string
    {
        $description = $this->messages->cleanText($description);

        return $description === '' ? null : mb_substr($description, 0, self::MAX_DESCRIPTION);
    }

    private function storeAvatar(UploadedFile $file, ?string $previous = null): string
    {
        try {
            return $this->images->storeAvatar($file, $previous);
        } catch (RuntimeException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }
}
