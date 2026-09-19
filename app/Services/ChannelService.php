<?php

namespace App\Services;

use App\Events\GroupUpdated;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * G11 — Channels: one-way updates from admins to followers.
 *
 * A channel is a conversation of type "channel". Its admins and followers are in
 * conversation_members; followers see every update (also the ones from before
 * they followed), can react and vote, but cannot post. Followers never see each
 * other, and updates are shown as coming from the channel, not from a person.
 */
class ChannelService
{
    public const DIRECTORY_LIMIT = 30;

    public const PREVIEW_UPDATES = 20;

    public function __construct(
        private readonly MessageService $messages,
        private readonly ImageService $images,
        private readonly AttachmentService $attachments,
    ) {}

    public function create(User $owner, string $name, ?string $description = null, ?UploadedFile $avatar = null): Conversation
    {
        $name = $this->cleanName($name);
        $avatarPath = $avatar ? $this->storeAvatar($avatar) : null;

        $channel = DB::transaction(function () use ($owner, $name, $description, $avatarPath) {
            $channel = Conversation::create([
                'type' => Conversation::TYPE_CHANNEL,
                'name' => $name,
                'description' => $this->cleanDescription($description),
                'avatar' => $avatarPath,
                'created_by' => $owner->getKey(),
                'only_admins_send' => true,
                'only_admins_edit' => true,
                'invite_token' => Str::random(22),
            ]);

            $channel->members()->create([
                'user_id' => $owner->getKey(),
                'role' => ConversationMember::ROLE_ADMIN,
                'joined_at' => now(),
            ]);

            return $channel;
        });

        // The first line of the channel (and what puts it in the chat list).
        $this->messages->systemNotice($owner, $channel, ['event' => 'channel_created', 'name' => $name]);

        return $channel->fresh();
    }

    /**
     * @param  array{name?: string, description?: ?string}  $changes
     */
    public function update(Conversation $channel, User $by, array $changes, ?UploadedFile $avatar = null, bool $removeAvatar = false): Conversation
    {
        $this->ensureAdmin($channel, $by);

        if (array_key_exists('name', $changes)) {
            $channel->name = $this->cleanName((string) $changes['name']);
        }
        if (array_key_exists('description', $changes)) {
            $channel->description = $this->cleanDescription($changes['description']);
        }
        if ($avatar) {
            $channel->avatar = $this->storeAvatar($avatar, $channel->avatar);
        } elseif ($removeAvatar && $channel->avatar) {
            $this->images->deleteAvatar($channel->avatar);
            $channel->avatar = null;
        }

        $channel->save();
        broadcast(new GroupUpdated($channel));

        return $channel;
    }

    /** Deleting a channel removes it and its updates for everyone. */
    public function delete(Conversation $channel, User $by): void
    {
        $this->ensureAdmin($channel, $by);
        $this->destroy($channel);
    }

    /** Remove a channel with its updates and files (also used by the admin panel). */
    public function destroy(Conversation $channel): void
    {
        abort_unless($channel->isChannel(), 404);

        broadcast(new GroupUpdated($channel));

        $channel->messages()->whereNotNull('attachment')->lazyById(200)
            ->each(fn (Message $message) => $this->attachments->delete($message));
        if ($channel->avatar) {
            $this->images->deleteAvatar($channel->avatar);
        }

        // A promoted channel (Y2) stops showing and the unused coins come back.
        app(PromotionService::class)->stopForTarget('conversation', (int) $channel->getKey(), 'target_gone');
        $channel->delete();
    }

    public function follow(Conversation $channel, User $user): Conversation
    {
        $this->ensureChannel($channel);

        if ($channel->isActiveMember($user)) {
            return $channel;
        }

        // Followers see the whole channel; what was posted before counts as read.
        $newest = (int) $channel->messages()->max('id');
        $channel->members()->updateOrCreate(['user_id' => $user->getKey()], [
            'role' => ConversationMember::ROLE_MEMBER,
            'joined_at' => now(),
            'left_at' => null,
            'visible_from_message_id' => 0,
            'visible_until_message_id' => null,
            'last_read_message_id' => $newest,
            'last_delivered_message_id' => $newest,
        ]);
        $channel->unsetRelation('members');

        return $channel;
    }

    public function unfollow(Conversation $channel, User $user): void
    {
        $this->ensureChannel($channel);
        $member = $channel->memberFor($user);

        if (! $member) {
            return;
        }

        if ($member->isAdmin()) {
            throw new HttpException(422, 'Admins cannot unfollow their channel. Delete the channel instead.');
        }

        $member->delete();
        $channel->unsetRelation('members');
    }

    /**
     * Channels anyone can find, most followed first.
     *
     * @return Collection<int, Conversation>
     */
    public function directory(?string $search = null): Collection
    {
        $search = trim((string) $search);
        $like = '%'.addcslashes($search, '\\%_').'%';

        return Conversation::query()
            ->where('type', Conversation::TYPE_CHANNEL)
            ->whereNull('ended_at')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('description', 'like', $like)))
            ->withCount(['activeMembers as followers_count' => fn ($q) => $q->where('role', ConversationMember::ROLE_MEMBER)])
            ->orderByDesc('followers_count')
            ->orderByDesc('id')
            ->limit(self::DIRECTORY_LIMIT)
            ->get();
    }

    public function findByInvite(string $token): ?Conversation
    {
        return Conversation::query()->where('type', Conversation::TYPE_CHANNEL)->where('invite_token', $token)->whereNull('ended_at')->first();
    }

    public function link(Conversation $channel): string
    {
        return route('channels.link', $channel->invite_token);
    }

    /**
     * Opening the channel: every update is read (no receipts for channels).
     */
    public function markRead(Conversation $channel, User $reader): void
    {
        $member = $channel->memberFor($reader);
        if (! $member) {
            return;
        }

        $newest = (int) $channel->messages()->max('id');
        if ($newest > $member->last_read_message_id) {
            $member->forceFill(['last_read_message_id' => $newest, 'last_delivered_message_id' => max($member->last_delivered_message_id, $newest)])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Conversation $channel, User $viewer): array
    {
        $mine = $channel->getAttribute('my_membership') ?? $channel->memberFor($viewer);
        $following = (bool) $mine?->isActive();
        $admin = $following && $mine->isAdmin();

        return [
            'name' => $channel->name,
            'description' => $channel->description,
            'avatar_url' => $channel->groupAvatarUrl(),
            'initials' => $channel->groupInitials(),
            'avatar_hue' => $channel->groupHue(),
            'created_at' => $channel->created_at?->toIso8601String(),
            'followers_count' => (int) ($channel->getAttribute('followers_count') ?? $channel->activeMembers()->where('role', ConversationMember::ROLE_MEMBER)->count()),
            'is_following' => $following,
            'is_admin' => $admin,
            'can_send' => $admin,
            'link' => $channel->invite_token ? $this->link($channel) : null,
        ];
    }

    /**
     * What someone sees before following: the channel and its latest updates.
     *
     * @return array<string, mixed>
     */
    public function preview(Conversation $channel, User $viewer): array
    {
        $this->ensureChannel($channel);

        $updates = $channel->messages()
            ->where('message_type', '!=', Message::TYPE_SYSTEM)
            ->where('deleted_for_everyone', false)
            ->withCount('reactions')
            ->latest('id')
            ->limit(self::PREVIEW_UPDATES)
            ->get()
            ->reverse()
            ->map(fn (Message $message) => [
                'id' => $message->id,
                'type' => $message->message_type,
                'preview' => $message->preview(300),
                'reactions_count' => (int) $message->reactions_count,
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->values();

        return ['id' => $channel->id] + $this->payload($channel, $viewer) + ['updates' => $updates];
    }

    private function ensureChannel(Conversation $channel): void
    {
        if (! $channel->isChannel() || $channel->ended_at !== null) {
            throw new HttpException(404, 'This channel does not exist.');
        }
    }

    private function ensureAdmin(Conversation $channel, User $user): void
    {
        $this->ensureChannel($channel);

        if (! $channel->isAdmin($user)) {
            throw new HttpException($channel->isActiveMember($user) ? 403 : 404, 'Only channel admins can do this.');
        }
    }

    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $this->messages->cleanText($name)));
        if ($name === '') {
            throw new HttpException(422, 'Give the channel a name.');
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
