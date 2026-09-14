<?php

namespace App\Services;

use App\Models\BlockedUser;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Phase 6 — who sees my last seen, online, profile photo and About (P1, P2, P4).
 *
 * "My contacts" means the same as for status: people the owner saved in their
 * phone book and people the owner has a one-to-one chat with. People who block
 * each other never see each other's details. Remembered for one request.
 */
class PrivacyService
{
    public const EVERYONE = 'everyone';

    public const CONTACTS = 'contacts';

    public const NOBODY = 'nobody';

    /** Online: "same as last seen". */
    public const SAME = 'same';

    public const MODES = [self::EVERYONE, self::CONTACTS, self::NOBODY];

    public const ONLINE_MODES = [self::EVERYONE, self::SAME];

    /** @var array<int, array<int, true>> viewer id => ids of people who count the viewer as a contact */
    private array $contactOf = [];

    /** @var array<int, array<int, true>> viewer id => ids of people blocked either way */
    private array $blocked = [];

    public function canSeeLastSeen(User $owner, ?User $viewer): bool
    {
        if ($this->isSelf($owner, $viewer)) {
            return true;
        }

        // Like WhatsApp: if you share your last seen with nobody, you don't see anyone's.
        if ($viewer && $viewer->last_seen_privacy === self::NOBODY) {
            return false;
        }

        return $this->allowed($owner, $viewer, $owner->last_seen_privacy);
    }

    public function canSeeOnline(User $owner, ?User $viewer): bool
    {
        if ($this->isSelf($owner, $viewer)) {
            return true;
        }

        return $owner->online_privacy === self::SAME
            ? $this->allowed($owner, $viewer, $owner->last_seen_privacy)
            : $this->allowed($owner, $viewer, self::EVERYONE);
    }

    public function canSeePhoto(User $owner, ?User $viewer): bool
    {
        return $this->isSelf($owner, $viewer) || $this->allowed($owner, $viewer, $owner->photo_privacy);
    }

    public function canSeeAbout(User $owner, ?User $viewer): bool
    {
        return $this->isSelf($owner, $viewer) || $this->allowed($owner, $viewer, $owner->about_privacy);
    }

    /**
     * Presence as $viewer may see it.
     *
     * @return array{is_online: bool, last_seen: ?string}
     */
    public function presenceFor(User $owner, ?User $viewer): array
    {
        return [
            'is_online' => $this->canSeeOnline($owner, $viewer) && $owner->isOnlineNow(),
            'last_seen' => $this->canSeeLastSeen($owner, $viewer) ? $owner->last_seen?->toIso8601String() : null,
        ];
    }

    /**
     * People who may hear about $owner coming online or going offline: their one-to-one
     * chat partners and the people who saved them, grouped by what each may see.
     *
     * @return array{full: list<int>, online_only: list<int>, last_seen_only: list<int>}
     */
    public function presenceAudience(User $owner): array
    {
        $id = (int) $owner->getKey();
        $candidates = $this->chatPartnerIds($id)
            ->concat(Contact::query()->where('contact_user_id', $id)->pluck('user_id'))
            ->map(fn ($other) => (int) $other)
            ->reject(fn (int $other) => $other === $id)
            ->unique()
            ->values();

        $groups = ['full' => [], 'online_only' => [], 'last_seen_only' => []];
        if ($candidates->isEmpty()) {
            return $groups;
        }

        foreach (User::query()->whereKey($candidates)->active()->get() as $viewer) {
            $online = $this->canSeeOnline($owner, $viewer);
            $lastSeen = $this->canSeeLastSeen($owner, $viewer);

            match (true) {
                $online && $lastSeen => $groups['full'][] = (int) $viewer->getKey(),
                $online => $groups['online_only'][] = (int) $viewer->getKey(),
                $lastSeen => $groups['last_seen_only'][] = (int) $viewer->getKey(),
                default => null,
            };
        }

        return $groups;
    }

    /** Owner's setting decides; "contacts" means the owner counts the viewer as a contact. */
    private function allowed(User $owner, ?User $viewer, ?string $mode): bool
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : self::EVERYONE;

        if ($viewer === null) {
            return $mode === self::EVERYONE;
        }

        if (isset($this->blockedFor((int) $viewer->getKey())[(int) $owner->getKey()])) {
            return false;
        }

        return match ($mode) {
            self::EVERYONE => true,
            self::NOBODY => false,
            default => isset($this->contactOwnersFor((int) $viewer->getKey())[(int) $owner->getKey()]),
        };
    }

    private function isSelf(User $owner, ?User $viewer): bool
    {
        return $viewer !== null && (int) $viewer->getKey() === (int) $owner->getKey();
    }

    /**
     * @return array<int, true>
     */
    private function contactOwnersFor(int $viewerId): array
    {
        return $this->contactOf[$viewerId] ??= Contact::query()->where('contact_user_id', $viewerId)->pluck('user_id')
            ->concat($this->peopleWhoWroteTo($viewerId))
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * @return array<int, true>
     */
    private function blockedFor(int $viewerId): array
    {
        return $this->blocked[$viewerId] ??= BlockedUser::query()->where('user_id', $viewerId)->pluck('blocked_user_id')
            ->concat(BlockedUser::query()->where('blocked_user_id', $viewerId)->pluck('user_id'))
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
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
     * People who sent $userId a one-to-one message: $userId is in their contacts.
     * A stranger's unanswered "hi" doesn't make them a contact of the person they wrote to.
     *
     * @return Collection<int, int>
     */
    private function peopleWhoWroteTo(int $userId): Collection
    {
        return Message::query()->where('receiver_id', $userId)->where('sender_id', '!=', $userId)
            ->where('message_type', '!=', Message::TYPE_SYSTEM)
            ->distinct()->pluck('sender_id')->map(fn ($id) => (int) $id);
    }
}
