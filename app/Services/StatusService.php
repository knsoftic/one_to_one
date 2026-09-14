<?php

namespace App\Services;

use App\Events\StatusUpdated;
use App\Events\StatusViewed;
use App\Models\BlockedUser;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Status;
use App\Models\StatusView;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Phase 5 — Status: 24-hour text, photo and video updates.
 *
 * Who sees an update: "My contacts" are the people you saved in your phone book
 * and the people you have a one-to-one chat with. The owner's status privacy is
 * copied onto each update when it is posted, so changing it later only affects
 * new updates (like WhatsApp). People who block each other never see each other's updates.
 */
class StatusService
{
    public const MAX_TEXT = 700;

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly MessageService $messages,
        private readonly ConversationService $conversations,
    ) {}

    public function lifetimeHours(): int
    {
        return max(1, (int) config('chat.statuses.lifetime_hours', 24));
    }

    /* ------------------------------------------------------------------ */
    /* S1 — Post and delete */
    /* ------------------------------------------------------------------ */

    public function createText(User $owner, string $text, ?string $background, int $font): Status
    {
        $text = mb_substr($this->messages->cleanText($text), 0, self::MAX_TEXT);
        if (trim($text) === '') {
            throw new HttpException(422, 'Type something for your status.');
        }

        return $this->publish($owner, [
            'type' => Status::TYPE_TEXT,
            'body' => $text,
            'background' => in_array($background, Status::BACKGROUNDS, true) ? $background : Status::BACKGROUNDS[0],
            'font' => max(0, min(Status::FONTS - 1, $font)),
        ]);
    }

    public function createMedia(User $owner, UploadedFile $file, string $type, ?string $caption, ?UploadedFile $thumbnail = null, ?float $duration = null): Status
    {
        $messageType = $type === Status::TYPE_VIDEO ? Message::TYPE_VIDEO : Message::TYPE_IMAGE;
        $meta = $type === Status::TYPE_VIDEO && $duration !== null ? ['duration' => round($duration, 1)] : [];
        $stored = $this->attachments->store($file, $messageType, $meta, $type === Status::TYPE_VIDEO ? $thumbnail : null);
        $caption = mb_substr($this->messages->cleanText($caption), 0, self::MAX_TEXT);

        try {
            return $this->publish($owner, [
                'type' => $type,
                'body' => $caption !== '' ? $caption : null,
                'attachment' => $stored['attachment'],
                'attachment_mime' => $stored['attachment_mime'],
                'attachment_size' => $stored['attachment_size'],
                'attachment_meta' => $stored['attachment_meta'] ?: null,
            ]);
        } catch (Throwable $e) {
            $this->deleteFiles($stored['attachment'], $stored['attachment_meta']['thumbnail'] ?? null);

            throw $e;
        }
    }

    public function delete(Status $status, User $by): void
    {
        if (! $status->isOwnedBy($by)) {
            throw new HttpException(404, 'This status does not exist.');
        }

        $audience = $this->audienceIds($status);
        $this->deleteFiles($status->attachment, $status->attachment_meta['thumbnail'] ?? null);
        $status->delete();

        broadcast(new StatusUpdated((int) $by->getKey(), [...$audience, (int) $by->getKey()]));
    }

    /** Updates whose 24 hours are over go, with their files. */
    public function expire(): int
    {
        $count = 0;

        Status::query()->where('expires_at', '<=', now())->chunkById(200, function (Collection $statuses) use (&$count) {
            foreach ($statuses as $status) {
                $this->deleteFiles($status->attachment, $status->attachment_meta['thumbnail'] ?? null);
            }
            Status::query()->whereKey($statuses->modelKeys())->delete();
            $count += $statuses->count();
        });

        return $count;
    }

    /** Absolute path of an update's photo / video (or its poster), or null. */
    public function mediaPath(Status $status, string $variant = 'original'): ?string
    {
        $relative = $variant === 'thumbnail' ? ($status->attachment_meta['thumbnail'] ?? null) : $status->attachment;

        return $relative && $this->attachments->disk()->exists($relative) ? $this->attachments->disk()->path($relative) : null;
    }

    /* ------------------------------------------------------------------ */
    /* Who sees what */
    /* ------------------------------------------------------------------ */

    /**
     * My contacts: people I saved, and people I have a one-to-one chat with.
     *
     * @return list<int>
     */
    public function connectionIds(User $user): array
    {
        $id = (int) $user->getKey();
        $saved = Contact::query()->where('user_id', $id)->pluck('contact_user_id');

        return $saved->concat($this->chatPartnerIds($id))
            ->map(fn ($other) => (int) $other)
            ->reject(fn (int $other) => $other === $id)
            ->unique()->values()->all();
    }

    public function canView(Status $status, User $viewer): bool
    {
        if ($status->isOwnedBy($viewer)) {
            return true;
        }

        $status->loadMissing('user');
        if (! $status->isActive() || $viewer->hasBlockWith((int) $status->user_id) || ! $status->user?->isActive()) {
            return false;
        }

        $viewerId = (int) $viewer->getKey();
        $ids = array_map('intval', $status->privacy_user_ids ?? []);

        if ($status->privacy === Status::PRIVACY_ONLY) {
            return in_array($viewerId, $ids, true);
        }

        if ($status->privacy === Status::PRIVACY_EXCEPT && in_array($viewerId, $ids, true)) {
            return false;
        }

        return $this->isConnection((int) $status->user_id, $viewerId);
    }

    /**
     * Everyone who may see an update (for live updates).
     *
     * @return list<int>
     */
    public function audienceIds(Status $status): array
    {
        $owner = $status->loadMissing('user')->user;
        if (! $owner) {
            return [];
        }

        $ids = array_map('intval', $status->privacy_user_ids ?? []);
        $people = match ($status->privacy) {
            Status::PRIVACY_ONLY => $ids,
            Status::PRIVACY_EXCEPT => array_values(array_diff($this->connectionIds($owner), $ids)),
            default => $this->connectionIds($owner),
        };

        return array_values(array_diff($people, $this->blockedIds((int) $owner->getKey()), [(int) $owner->getKey()]));
    }

    /**
     * My updates and the updates I may see, grouped by person.
     *
     * @return array{mine: Collection<int, Status>, updates: list<array{user: User, statuses: Collection<int, Status>, latest_at: mixed, viewed: bool, muted: bool}>}
     */
    public function feed(User $viewer): array
    {
        $viewerId = (int) $viewer->getKey();

        $mine = Status::query()->active()->where('user_id', $viewerId)->withCount('views')->orderBy('created_at')->get();

        $savedMe = Contact::query()->where('contact_user_id', $viewerId)->pluck('user_id')->map(fn ($id) => (int) $id);
        $connections = $savedMe->concat($this->chatPartnerIds($viewerId))->unique();
        $onlyForMe = Status::query()->active()->where('privacy', Status::PRIVACY_ONLY)
            ->whereJsonContains('privacy_user_ids', $viewerId)->pluck('user_id')->map(fn ($id) => (int) $id);

        $owners = $connections->concat($onlyForMe)->unique()
            ->reject(fn (int $id) => $id === $viewerId)
            ->diff($this->blockedIds($viewerId))
            ->values();

        if ($owners->isEmpty()) {
            return ['mine' => $mine, 'updates' => []];
        }

        $connectionSet = $connections->flip();
        $statuses = Status::query()->active()
            ->whereIn('user_id', $owners)
            ->whereHas('user', fn ($q) => $q->active())
            ->with('user')
            ->orderBy('created_at')
            ->get()
            ->filter(function (Status $status) use ($viewerId, $connectionSet) {
                $ids = array_map('intval', $status->privacy_user_ids ?? []);

                return match ($status->privacy) {
                    Status::PRIVACY_ONLY => in_array($viewerId, $ids, true),
                    Status::PRIVACY_EXCEPT => $connectionSet->has((int) $status->user_id) && ! in_array($viewerId, $ids, true),
                    default => $connectionSet->has((int) $status->user_id),
                };
            });

        $viewed = StatusView::query()->where('user_id', $viewerId)->whereIn('status_id', $statuses->modelKeys())->pluck('status_id')->flip();
        $muted = DB::table('status_mutes')->where('user_id', $viewerId)->pluck('muted_user_id')->map(fn ($id) => (int) $id)->flip();

        $statuses->each(fn (Status $status) => $status->setAttribute('viewed', $viewed->has($status->getKey())));

        $updates = $statuses->groupBy('user_id')
            ->map(fn (Collection $group, $userId) => [
                'user' => $group->first()->user,
                'statuses' => $group->values(),
                'latest_at' => $group->max('created_at'),
                'viewed' => $group->every(fn (Status $status) => $status->getAttribute('viewed')),
                'muted' => $muted->has((int) $userId),
            ])
            // Not seen yet first, newest first within each part.
            ->sortBy(fn (array $entry) => [$entry['viewed'] ? 1 : 0, -$entry['latest_at']->getTimestamp()])
            ->values()
            ->all();

        return ['mine' => $mine, 'updates' => $updates];
    }

    /* ------------------------------------------------------------------ */
    /* S2 — Views */
    /* ------------------------------------------------------------------ */

    public function markViewed(Status $status, User $viewer): void
    {
        $this->ensureCanView($status, $viewer);

        if ($status->isOwnedBy($viewer)) {
            return;
        }

        $view = StatusView::query()->firstOrCreate(
            ['status_id' => $status->getKey(), 'user_id' => $viewer->getKey()],
            ['viewed_at' => now()],
        );

        if ($view->wasRecentlyCreated) {
            broadcast(new StatusViewed((int) $status->user_id, (int) $status->getKey(), $status->views()->count()));
        }
    }

    /**
     * Who saw my update, newest first (with their reaction).
     *
     * @return Collection<int, StatusView>
     */
    public function viewers(Status $status, User $owner): Collection
    {
        if (! $status->isOwnedBy($owner)) {
            throw new HttpException(404, 'This status does not exist.');
        }

        return $status->views()->with('user')->whereHas('user')->orderByDesc('viewed_at')->get();
    }

    /* ------------------------------------------------------------------ */
    /* S3 — Privacy */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{mode: string, except_ids: list<int>, only_ids: list<int>}
     */
    public function privacyFor(User $user): array
    {
        $lists = DB::table('status_privacy_users')->where('user_id', $user->getKey())->get(['member_id', 'list'])->groupBy('list');

        return [
            'mode' => in_array($user->status_privacy, Status::PRIVACY_MODES, true) ? $user->status_privacy : Status::PRIVACY_CONTACTS,
            'except_ids' => $lists->get(Status::PRIVACY_EXCEPT, collect())->pluck('member_id')->map(fn ($id) => (int) $id)->values()->all(),
            'only_ids' => $lists->get(Status::PRIVACY_ONLY, collect())->pluck('member_id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /**
     * @param  list<int>|null  $exceptIds  null keeps the saved list
     * @param  list<int>|null  $onlyIds  null keeps the saved list
     * @return array{mode: string, except_ids: list<int>, only_ids: list<int>}
     */
    public function updatePrivacy(User $user, string $mode, ?array $exceptIds, ?array $onlyIds): array
    {
        DB::transaction(function () use ($user, $mode, $exceptIds, $onlyIds) {
            foreach ([Status::PRIVACY_EXCEPT => $exceptIds, Status::PRIVACY_ONLY => $onlyIds] as $list => $ids) {
                if ($ids === null) {
                    continue;
                }

                $valid = User::query()->whereKey(array_map('intval', $ids))->where('id', '!=', $user->getKey())->active()->pluck('id');
                DB::table('status_privacy_users')->where('user_id', $user->getKey())->where('list', $list)->delete();
                DB::table('status_privacy_users')->insert($valid->map(fn ($id) => ['user_id' => $user->getKey(), 'member_id' => $id, 'list' => $list])->all());
            }

            $user->forceFill(['status_privacy' => $mode])->save();
        });

        $privacy = $this->privacyFor($user);
        if ($mode === Status::PRIVACY_ONLY && $privacy['only_ids'] === []) {
            $user->forceFill(['status_privacy' => Status::PRIVACY_CONTACTS])->save();
            throw new HttpException(422, 'Choose at least one person to share your status with.');
        }

        return $privacy;
    }

    /* ------------------------------------------------------------------ */
    /* S4 — Reply and react */
    /* ------------------------------------------------------------------ */

    /** A reply goes to the one-to-one chat with the owner, quoting the update. */
    public function reply(Status $status, User $viewer, string $text): Message
    {
        $conversation = $this->chatAbout($status, $viewer);

        return $this->messages->sendStatusReply($viewer, $conversation, $text, $this->quote($status));
    }

    /**
     * React with an emoji: kept on the view and sent to the chat once per new emoji.
     */
    public function react(Status $status, User $viewer, string $emoji): ?Message
    {
        $conversation = $this->chatAbout($status, $viewer);

        $view = StatusView::query()->firstOrNew(['status_id' => $status->getKey(), 'user_id' => $viewer->getKey()]);
        if ($view->exists && $view->reaction === $emoji) {
            return null;
        }

        $isNew = ! $view->exists;
        $view->forceFill(['viewed_at' => $view->viewed_at ?? now(), 'reaction' => $emoji])->save();
        if ($isNew) {
            broadcast(new StatusViewed((int) $status->user_id, (int) $status->getKey(), $status->views()->count()));
        }

        return $this->messages->sendStatusReply($viewer, $conversation, $emoji, $this->quote($status) + ['reaction' => true]);
    }

    /* ------------------------------------------------------------------ */
    /* S5 — Mute */
    /* ------------------------------------------------------------------ */

    public function mute(User $user, User $other, bool $muted): void
    {
        if ($user->is($other)) {
            throw new HttpException(422, "You can't mute your own status.");
        }

        if ($muted) {
            DB::table('status_mutes')->insertOrIgnore(['user_id' => $user->getKey(), 'muted_user_id' => $other->getKey(), 'created_at' => now()]);
        } else {
            DB::table('status_mutes')->where('user_id', $user->getKey())->where('muted_user_id', $other->getKey())->delete();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Payload */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function payload(Status $status, User $viewer): array
    {
        $meta = $status->attachment_meta ?? [];
        $mine = $status->isOwnedBy($viewer);

        return [
            'id' => $status->id,
            'user_id' => (int) $status->user_id,
            'type' => $status->type,
            'text' => $status->body,
            'background' => $status->background,
            'font' => (int) $status->font,
            'media_url' => $status->attachment ? route('statuses.media', $status, false) : null,
            'thumbnail_url' => ! empty($meta['thumbnail']) ? route('statuses.media', [$status, 'variant' => 'thumbnail'], false) : null,
            'width' => $meta['width'] ?? null,
            'height' => $meta['height'] ?? null,
            'duration' => $meta['duration'] ?? null,
            'viewed' => $mine || (bool) $status->getAttribute('viewed'),
            'created_at' => $status->created_at?->toIso8601String(),
            'expires_at' => $status->expires_at?->toIso8601String(),
        ] + ($mine ? [
            'views_count' => (int) ($status->getAttribute('views_count') ?? $status->views()->count()),
            'privacy' => $status->privacy,
        ] : []);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function publish(User $owner, array $attributes): Status
    {
        $privacy = $this->privacyFor($owner);

        $status = Status::create($attributes + [
            'user_id' => $owner->getKey(),
            'privacy' => $privacy['mode'],
            'privacy_user_ids' => match ($privacy['mode']) {
                Status::PRIVACY_EXCEPT => $privacy['except_ids'],
                Status::PRIVACY_ONLY => $privacy['only_ids'],
                default => null,
            },
            'expires_at' => now()->addHours($this->lifetimeHours()),
        ]);
        $status->setRelation('user', $owner);

        broadcast(new StatusUpdated((int) $owner->getKey(), [...$this->audienceIds($status), (int) $owner->getKey()]));

        return $status;
    }

    private function ensureCanView(Status $status, User $viewer): void
    {
        if (! $this->canView($status, $viewer)) {
            throw new HttpException(404, 'This status is no longer available.');
        }
    }

    /** The one-to-one chat where a reply or reaction to someone's update goes. */
    private function chatAbout(Status $status, User $viewer): Conversation
    {
        $this->ensureCanView($status, $viewer);

        if ($status->isOwnedBy($viewer)) {
            throw new HttpException(422, "You can't reply to your own status.");
        }

        $conversation = $this->conversations->findOrCreate($viewer, $status->user);
        Gate::forUser($viewer)->authorize('sendMessage', $conversation);

        return $conversation;
    }

    /**
     * What a chat message shows about the update it answers.
     *
     * @return array<string, mixed>
     */
    private function quote(Status $status): array
    {
        return array_filter([
            'id' => $status->id,
            'owner_id' => (int) $status->user_id,
            'type' => $status->type,
            'text' => $status->body !== null ? (string) str($status->body)->squish()->limit(120) : null,
            'background' => $status->background,
            'font' => $status->type === Status::TYPE_TEXT ? (int) $status->font : null,
            'has_thumbnail' => ! empty($status->attachment_meta['thumbnail']) ?: null,
            'expires_at' => $status->expires_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    private function isConnection(int $ownerId, int $viewerId): bool
    {
        return Contact::query()->where('user_id', $ownerId)->where('contact_user_id', $viewerId)->exists()
            || Conversation::query()->between($ownerId, $viewerId)->whereNotNull('last_message_id')->exists();
    }

    /**
     * People with a one-to-one chat (with messages) with $userId.
     *
     * @return Collection<int, int>
     */
    private function chatPartnerIds(int $userId): Collection
    {
        return Conversation::query()
            ->where('type', Conversation::TYPE_DIRECT)
            ->whereNotNull('last_message_id')
            ->where(fn ($q) => $q->where('user_one_id', $userId)->orWhere('user_two_id', $userId))
            ->get(['user_one_id', 'user_two_id'])
            ->map(fn (Conversation $c) => (int) $c->user_one_id === $userId ? (int) $c->user_two_id : (int) $c->user_one_id)
            ->reject(fn (int $id) => $id === $userId)
            ->values();
    }

    /**
     * People $userId blocked or who blocked $userId.
     *
     * @return list<int>
     */
    private function blockedIds(int $userId): array
    {
        return BlockedUser::query()->where('user_id', $userId)->pluck('blocked_user_id')
            ->concat(BlockedUser::query()->where('blocked_user_id', $userId)->pluck('user_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function deleteFiles(?string ...$paths): void
    {
        $paths = array_values(array_filter($paths));
        if ($paths !== []) {
            $this->attachments->disk()->delete($paths);
        }
    }
}
