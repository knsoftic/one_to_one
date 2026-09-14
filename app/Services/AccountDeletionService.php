<?php

namespace App\Services;

use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\OtpCode;
use App\Models\Status;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A3 — deletes an account for good ("Delete my account" in settings, and the admin panel).
 *
 * Like WhatsApp: the person leaves their groups and communities (an admin is chosen
 * when they were the last one), their channels and broadcast lists are deleted, and
 * their one-to-one chats, messages, status updates, files and settings are removed.
 * Groups, communities and channels of other people stay.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly GroupService $groups,
        private readonly CommunityService $communities,
        private readonly ChannelService $channels,
        private readonly BroadcastService $broadcasts,
        private readonly ImageService $images,
        private readonly PresenceService $presence,
    ) {}

    public function delete(User $user): void
    {
        $id = (int) $user->getKey();
        $directIds = Conversation::query()
            ->where('type', Conversation::TYPE_DIRECT)
            ->where(fn ($q) => $q->where('user_one_id', $id)->orWhere('user_two_id', $id))
            ->pluck('id');

        // Every file that goes, listed before anything is deleted.
        $files = $this->files($user, $directIds);
        $avatar = $user->profile_image;

        $this->presence->markOffline($user);
        $this->leaveCommunities($user);
        $this->leaveGroupsAndChannels($user);
        Conversation::query()->where('type', Conversation::TYPE_BROADCAST)->where('created_by', $id)->get()
            ->each(fn (Conversation $list) => $this->broadcasts->delete($list, $user));

        DB::transaction(function () use ($user, $id, $directIds) {
            $doomed = Message::query()->where(fn ($q) => $q->where('sender_id', $id)->orWhereIn('conversation_id', $directIds));
            $groupIds = (clone $doomed)->whereNotIn('conversation_id', $directIds)->distinct()->pluck('conversation_id');

            // Break references to the messages first so the deletes never cascade into each other.
            (clone $doomed)->select('id')->chunkById(1000, function (Collection $chunk) {
                $ids = $chunk->modelKeys();
                Message::query()->whereIn('reply_to_id', $ids)->update(['reply_to_id' => null]);
                Message::query()->whereIn('broadcast_message_id', $ids)->update(['broadcast_message_id' => null]);
                Conversation::query()->whereIn('last_message_id', $ids)->update(['last_message_id' => null]);
            });
            (clone $doomed)->delete();
            Conversation::query()->whereIn('id', $directIds)->delete();

            // Groups and channels point at their newest remaining message again.
            Conversation::query()->whereIn('id', $groupIds)->whereNull('last_message_id')->get()
                ->each(fn (Conversation $conversation) => $conversation->forceFill(['last_message_id' => $conversation->messages()->max('id')])->save());

            BlockedUser::query()->where('user_id', $id)->orWhere('blocked_user_id', $id)->delete();

            // Notifications the person received and notifications about their messages.
            DB::table('notifications')
                ->where(fn ($q) => $q->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $id))
                ->orWhere('data->sender->id', $id)
                ->delete();

            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            OtpCode::query()->where('phone', $user->phone)->delete();

            $user->delete();
        });

        if ($files !== []) {
            Storage::disk(config('chat.uploads.disk'))->delete($files);
        }
        $this->images->deleteAvatar($avatar);
    }

    /**
     * @param  Collection<int, int>  $directIds
     * @return list<string>
     */
    private function files(User $user, Collection $directIds): array
    {
        $files = [];
        $collect = function (?string $path, ?array $meta) use (&$files) {
            array_push($files, ...array_filter([$path, $meta['thumbnail'] ?? null]));
        };

        Message::query()
            ->where(fn ($q) => $q->where('sender_id', $user->getKey())->orWhereIn('conversation_id', $directIds))
            ->whereNotNull('attachment')
            ->select(['id', 'attachment', 'attachment_meta'])
            ->chunkById(500, fn (Collection $messages) => $messages->each(fn (Message $m) => $collect($m->attachment, $m->attachment_meta)));

        Status::query()->where('user_id', $user->getKey())->whereNotNull('attachment')->get(['id', 'attachment', 'attachment_meta'])
            ->each(fn (Status $status) => $collect($status->attachment, $status->attachment_meta));

        $user->stickers()->pluck('path')->each(fn ($path) => $collect($path, null));

        return array_values(array_unique($files));
    }

    /** Communities: hand the admin role on (or delete a community nobody else is in), then leave. */
    private function leaveCommunities(User $user): void
    {
        $announcements = Conversation::query()
            ->where('type', Conversation::TYPE_GROUP)
            ->where('is_announcement', true)
            ->whereNotNull('community_id')
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->getKey())->whereNull('left_at'))
            ->with('community')
            ->get();

        foreach ($announcements as $announcement) {
            $community = $announcement->community;
            if (! $community) {
                continue;
            }

            $others = $announcement->activeMembers()->where('user_id', '!=', $user->getKey())->orderBy('joined_at')->orderBy('id')->get();

            if ($others->isEmpty() && $announcement->isAdmin($user)) {
                $this->communities->delete($community, $user);

                continue;
            }

            if ($announcement->isAdmin($user) && ! $others->contains(fn (ConversationMember $m) => $m->role === ConversationMember::ROLE_ADMIN)) {
                $others->first()->forceFill(['role' => ConversationMember::ROLE_ADMIN])->save();
                $announcement->unsetRelation('members');
            }

            $this->communities->leave($community, $user);
        }
    }

    private function leaveGroupsAndChannels(User $user): void
    {
        $conversations = Conversation::query()
            ->whereIn('type', [Conversation::TYPE_GROUP, Conversation::TYPE_CHANNEL])
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->getKey())->whereNull('left_at'))
            ->get();

        foreach ($conversations as $conversation) {
            if ($conversation->isChannel()) {
                // A channel goes with its only admin; followers simply stop following.
                $otherAdmins = $conversation->activeMembers()->where('user_id', '!=', $user->getKey())->where('role', ConversationMember::ROLE_ADMIN)->exists();
                if ($conversation->isAdmin($user) && ! $otherAdmins) {
                    $this->channels->delete($conversation, $user);
                }

                continue;
            }

            if ($conversation->ended_at === null && $conversation->isActiveMember($user)) {
                $this->groups->leaveGroup($conversation, $user);
            }
        }
    }
}
