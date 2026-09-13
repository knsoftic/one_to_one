<?php

namespace App\Services;

use App\Events\UserPresenceChanged;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Maintains the users.is_online / users.last_seen columns and broadcasts
 * online/offline transitions.
 *
 * Writes use the base query builder so "updated_at" is not touched and model
 * events are not fired for every heartbeat.
 */
class PresenceService
{
    /** Minimum seconds between two activity writes for the same user. */
    private const WRITE_THROTTLE_SECONDS = 30;

    /**
     * Record activity for the user. Returns true when the user just came online.
     */
    public function touch(User $user, bool $force = false): bool
    {
        $wasOnline = $user->isOnlineNow();

        if (! $force && $wasOnline && $user->last_seen->gt(now()->subSeconds(self::WRITE_THROTTLE_SECONDS))) {
            return false;
        }

        $this->write($user, true, now());

        if (! $wasOnline) {
            broadcast(UserPresenceChanged::for($user));
        }

        return ! $wasOnline;
    }

    /**
     * Explicitly mark the user offline (logout, tab closed).
     */
    public function markOffline(User $user): void
    {
        $this->write($user, false, now());

        broadcast(UserPresenceChanged::for($user));
    }

    /**
     * Flag users whose activity is older than the online threshold as offline.
     *
     * @return int Number of users marked offline.
     */
    public function sweepStale(): int
    {
        $stale = User::query()
            ->where('is_online', true)
            ->where(fn ($q) => $q->whereNull('last_seen')
                ->orWhere('last_seen', '<', now()->subSeconds(config('chat.online_threshold_seconds'))))
            ->limit(1000)
            ->get(['id', 'last_seen']);

        if ($stale->isEmpty()) {
            return 0;
        }

        User::query()->whereKey($stale->modelKeys())->toBase()->update(['is_online' => false]);

        foreach ($stale as $user) {
            broadcast(new UserPresenceChanged($user->id, false, $user->last_seen?->toIso8601String()));
        }

        return $stale->count();
    }

    private function write(User $user, bool $online, Carbon $at): void
    {
        User::query()->whereKey($user->getKey())->toBase()->update([
            'is_online' => $online,
            'last_seen' => $at,
        ]);

        $user->forceFill(['is_online' => $online, 'last_seen' => $at]);
        $user->syncOriginalAttributes(['is_online', 'last_seen']);
    }
}
