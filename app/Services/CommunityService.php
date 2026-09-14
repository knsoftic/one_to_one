<?php

namespace App\Services;

use App\Events\GroupUpdated;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * G10 — Communities: groups under one roof. Everyone in the community is in its
 * announcement group (only admins post); community admins are that group's admins.
 * People in the community see its groups and can join them.
 */
class CommunityService
{
    public const MAX_GROUPS = 50;

    public function __construct(
        private readonly GroupService $groups,
        private readonly MessageService $messages,
        private readonly ImageService $images,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Create, edit, delete */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<int>  $groupIds  existing groups the creator runs, added to the community
     */
    public function create(User $creator, string $name, ?string $description, ?UploadedFile $avatar, array $groupIds = []): Community
    {
        $name = $this->cleanName($name);
        $avatarPath = $avatar ? $this->storeAvatar($avatar) : null;

        [$community, $announcement] = DB::transaction(function () use ($creator, $name, $description, $avatarPath) {
            $community = Community::create([
                'name' => $name,
                'description' => $this->cleanDescription($description),
                'avatar' => $avatarPath,
                'created_by' => $creator->getKey(),
            ]);

            $announcement = Conversation::create([
                'type' => Conversation::TYPE_GROUP,
                'name' => $name,
                'avatar' => $avatarPath,
                'created_by' => $creator->getKey(),
                'community_id' => $community->getKey(),
                'is_announcement' => true,
                'only_admins_send' => true,
                'only_admins_edit' => true,
            ]);

            $announcement->members()->create([
                'user_id' => $creator->getKey(),
                'role' => ConversationMember::ROLE_ADMIN,
                'joined_at' => now(),
            ]);

            return [$community, $announcement];
        });

        $this->notice($creator, $announcement, 'community_created', ['name' => $name]);

        foreach (array_unique(array_map('intval', $groupIds)) as $groupId) {
            $group = Conversation::query()->whereKey($groupId)->where('type', Conversation::TYPE_GROUP)->first();
            if ($group) {
                $this->linkGroup($community, $creator, $group);
            }
        }

        return $community->fresh();
    }

    /**
     * @param  array{name?: ?string, description?: ?string}  $changes
     */
    public function update(Community $community, User $by, array $changes, ?UploadedFile $avatar = null): Community
    {
        $this->ensureAdmin($community, $by);
        $announcement = $this->announcement($community);

        if (isset($changes['name'])) {
            $community->forceFill(['name' => $this->cleanName($changes['name'])])->save();
            $announcement->forceFill(['name' => $community->name])->save();
        }
        if (array_key_exists('description', $changes)) {
            $community->forceFill(['description' => $this->cleanDescription($changes['description'])])->save();
        }
        if ($avatar) {
            $path = $this->storeAvatar($avatar, $community->avatar);
            $community->forceFill(['avatar' => $path])->save();
            $announcement->forceFill(['avatar' => $path])->save();
        }

        $this->touch($community);

        return $community;
    }

    public function delete(Community $community, User $by): void
    {
        $this->ensureAdmin($community, $by);
        $this->destroy($community, $by);
    }

    /** The app's administrators delete a community (admin panel). Its groups stay as ordinary groups. */
    public function deleteByModerator(Community $community, User $admin): void
    {
        $this->destroy($community, $admin, GroupService::MODERATOR);
    }

    /**
     * @param  array{id: int, name: string}|null  $actor
     */
    private function destroy(Community $community, User $by, ?array $actor = null): void
    {
        $announcement = $this->announcement($community);

        foreach ($community->groups()->get() as $group) {
            $group->forceFill(['community_id' => null])->save();
            $this->notice($by, $group, 'community_unlinked', ['name' => $community->name], $actor);
        }

        if ($actor === null) {
            $this->groups->end($announcement, $by, forCommunity: true);
        } elseif ($announcement->ended_at === null) {
            $this->groups->endByModerator($announcement, $by);
        }
        $announcement->forceFill(['community_id' => null, 'is_announcement' => false])->save();
        $community->delete();
    }

    /* ------------------------------------------------------------------ */
    /* Groups */
    /* ------------------------------------------------------------------ */

    /** Add an existing group that $by is an admin of. */
    public function linkGroup(Community $community, User $by, Conversation $group): Conversation
    {
        $this->ensureAdmin($community, $by);

        if (! $group->isGroup() || $group->is_announcement || $group->ended_at !== null) {
            throw new HttpException(422, 'This group cannot be added to a community.');
        }
        if (! $group->isAdmin($by)) {
            throw new HttpException(403, 'Only admins of a group can add it to a community.');
        }
        if ($group->community_id && (int) $group->community_id !== (int) $community->getKey()) {
            throw new HttpException(422, 'This group is already in another community.');
        }
        if ((int) $group->community_id === (int) $community->getKey()) {
            return $group;
        }
        if ($community->groups()->count() >= self::MAX_GROUPS) {
            throw new HttpException(422, 'A community can have up to '.self::MAX_GROUPS.' groups.');
        }

        $group->forceFill(['community_id' => $community->getKey()])->save();
        $this->notice($by, $group, 'community_linked', ['name' => $community->name]);

        // Everyone in the group is now in the community.
        $this->ensureMembers($community, User::query()->whereKey($group->activeMembers()->pluck('user_id'))->get());
        $this->touch($community);

        return $group;
    }

    /**
     * @param  list<int>  $memberIds
     */
    public function createGroup(Community $community, User $by, string $name, array $memberIds = []): Conversation
    {
        $this->ensureAdmin($community, $by);

        $group = $this->groups->create($by, $name, $memberIds, null, null, allowEmpty: true);

        return $this->linkGroup($community, $by, $group);
    }

    public function unlinkGroup(Community $community, User $by, Conversation $group): void
    {
        $this->ensureAdmin($community, $by);
        abort_unless((int) $group->community_id === (int) $community->getKey() && ! $group->is_announcement, 404);

        $group->forceFill(['community_id' => null])->save();
        $this->notice($by, $group, 'community_unlinked', ['name' => $community->name]);
        $this->touch($community);
    }

    /** Someone in the community joins one of its groups. */
    public function joinGroup(Community $community, User $user, Conversation $group): Conversation
    {
        $this->ensureMember($community, $user);
        abort_unless((int) $group->community_id === (int) $community->getKey() && ! $group->is_announcement && $group->ended_at === null, 404);

        if ($group->isActiveMember($user)) {
            return $group;
        }
        if ($group->activeMembers()->count() >= $this->groups->maxMembers()) {
            throw new HttpException(422, 'This group is full.');
        }

        $this->groups->addPeople($group, collect([$user]), null, 'member_joined_community', $user);

        return $group;
    }

    /* ------------------------------------------------------------------ */
    /* People */
    /* ------------------------------------------------------------------ */

    /**
     * Put people into the announcement group (quietly) so they are in the community.
     *
     * @param  Collection<int, User>  $people
     */
    public function ensureMembers(Community $community, Collection $people): void
    {
        $announcement = $community->announcement()->first();
        if (! $announcement || $people->isEmpty()) {
            return;
        }

        $this->groups->addPeople($announcement, $people, null);
    }

    /** Leave the community: its announcements and every group of it. */
    public function leave(Community $community, User $user): void
    {
        $this->ensureMember($community, $user);
        $announcement = $this->announcement($community);

        if ($announcement->isAdmin($user) && $announcement->activeMembers()->where('role', ConversationMember::ROLE_ADMIN)->count() <= 1
            && $announcement->activeMembers()->count() > 1) {
            throw new HttpException(422, 'Make someone else a community admin before leaving.');
        }

        foreach ($community->groups()->get() as $group) {
            if ($group->isActiveMember($user)) {
                $this->groups->leaveGroup($group, $user);
            }
        }

        $this->groups->leaveGroup($announcement, $user);
    }

    public function removeMember(Community $community, User $by, User $target): void
    {
        $this->ensureAdmin($community, $by);

        if ($by->is($target)) {
            throw new HttpException(422, 'Use "Exit community" to leave the community.');
        }
        if ((int) $community->created_by === (int) $target->getKey()) {
            throw new HttpException(403, 'The community creator cannot be removed.');
        }

        foreach ([...$community->groups()->get(), $this->announcement($community)] as $group) {
            $member = $group->memberFor($target);
            if (! $member?->isActive()) {
                continue;
            }

            // The announcements never name members to each other; community groups do, like any group.
            $until = $group->is_announcement
                ? (int) $group->messages()->max('id')
                : $this->messages->systemNotice($by, $group, [
                    'event' => 'member_removed',
                    'actor' => ['id' => $by->getKey(), 'name' => $by->name],
                    'users' => [['id' => $target->getKey(), 'name' => $target->name]],
                ])->getKey();
            $member->forceFill(['left_at' => now(), 'role' => ConversationMember::ROLE_MEMBER, 'visible_until_message_id' => $until])->save();
            $group->unsetRelation('members');
            broadcast(new GroupUpdated($group, [(int) $target->getKey()]));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Invite link */
    /* ------------------------------------------------------------------ */

    public function inviteLink(Community $community, User $by, bool $reset = false): string
    {
        $this->ensureAdmin($community, $by);

        if ($reset || ! $community->invite_token) {
            $community->forceFill(['invite_token' => Str::random(22)])->save();
        }

        return route('communities.join.show', $community->invite_token);
    }

    public function findByInvite(string $token): ?Community
    {
        return Community::query()->where('invite_token', $token)->first();
    }

    public function joinWithLink(string $token, User $user): Community
    {
        $community = $this->findByInvite($token);
        if (! $community) {
            throw new HttpException(410, 'This invite link is no longer valid.');
        }

        $announcement = $this->announcement($community);
        if (! $announcement->isActiveMember($user)) {
            if ($announcement->activeMembers()->count() >= $this->groups->maxMembers() * 4) {
                throw new HttpException(422, 'This community is full.');
            }
            // Joining is not announced: members of a community don't see each other (like WhatsApp).
            $this->groups->addPeople($announcement, collect([$user]), null);
        }

        return $community;
    }

    /* ------------------------------------------------------------------ */
    /* Reading */
    /* ------------------------------------------------------------------ */

    /**
     * Communities the user is in.
     *
     * @return Collection<int, Community>
     */
    public function forUser(User $user): Collection
    {
        return Community::query()
            ->whereHas('announcement.members', fn ($q) => $q->where('user_id', $user->getKey())->whereNull('left_at'))
            ->orderBy('name')
            ->get();
    }

    public function isMember(Community $community, User $user): bool
    {
        return (bool) $community->announcement()->first()?->isActiveMember($user);
    }

    public function isAdmin(Community $community, User $user): bool
    {
        return (bool) $community->announcement()->first()?->isAdmin($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Community $community, User $viewer, bool $withGroups = true): array
    {
        $announcement = $community->announcement()->first();
        $groups = $withGroups ? $community->groups()->withCount('activeMembers')->orderBy('name')->get() : collect();
        $mine = $withGroups
            ? ConversationMember::query()->where('user_id', $viewer->getKey())->whereNull('left_at')->whereIn('conversation_id', $groups->modelKeys())->pluck('conversation_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return [
            'id' => $community->id,
            'name' => $community->name,
            'description' => $community->description,
            'avatar_url' => $community->avatarUrl(),
            'initials' => $community->initials(),
            'avatar_hue' => $community->hue(),
            'is_member' => (bool) $announcement?->isActiveMember($viewer),
            'is_admin' => (bool) $announcement?->isAdmin($viewer),
            'member_count' => $announcement ? $announcement->activeMembers()->count() : 0,
            'announcement_id' => $announcement?->id,
            'groups' => $groups->map(fn (Conversation $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'avatar_url' => $group->groupAvatarUrl(),
                'initials' => $group->groupInitials(),
                'avatar_hue' => $group->groupHue(),
                'member_count' => (int) $group->active_members_count,
                'is_member' => in_array((int) $group->id, $mine, true),
            ])->values()->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function announcement(Community $community): Conversation
    {
        return $community->announcement()->firstOrFail();
    }

    private function ensureMember(Community $community, User $user): void
    {
        abort_unless($this->isMember($community, $user), 404);
    }

    private function ensureAdmin(Community $community, User $user): void
    {
        $this->ensureMember($community, $user);

        if (! $this->isAdmin($community, $user)) {
            throw new HttpException(403, 'Only community admins can do this.');
        }
    }

    /** Tell everyone in the community's announcements that it changed. */
    private function touch(Community $community): void
    {
        if ($announcement = $community->announcement()->first()) {
            broadcast(new GroupUpdated($announcement));
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array{id: int, name: string}|null  $named  who the notice names (defaults to $actor)
     */
    private function notice(User $actor, Conversation $group, string $event, array $meta, ?array $named = null): void
    {
        $this->messages->systemNotice($actor, $group, ['event' => $event, 'actor' => $named ?? ['id' => $actor->getKey(), 'name' => $actor->name]] + $meta);
        broadcast(new GroupUpdated($group));
    }

    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $this->messages->cleanText($name)));
        if ($name === '') {
            throw new HttpException(422, 'Give the community a name.');
        }

        return mb_substr($name, 0, GroupService::MAX_NAME);
    }

    private function cleanDescription(?string $description): ?string
    {
        $description = $this->messages->cleanText($description);

        return $description === '' ? null : mb_substr($description, 0, GroupService::MAX_DESCRIPTION);
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
