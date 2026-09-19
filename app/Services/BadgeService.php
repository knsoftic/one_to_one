<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\AppSetting;
use App\Models\User;
use App\Notifications\MoneyNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The verified badge (Y2): bought with coins (users.verified_until), granted by an admin, or
 * included in the active plan (computed from the subscription snapshot, so it follows the plan).
 */
class BadgeService
{
    /**
     * Stored for a lifetime badge (badge_days = 0). `users.verified_until` is a MySQL TIMESTAMP,
     * which ends in January 2038, so the design's "2099-12-31" cannot be stored; anything in or
     * after LIFETIME_YEAR means "for good" — always test it with isLifetime(), never by year.
     */
    public const LIFETIME = '2038-01-01 00:00:00';

    public const LIFETIME_YEAR = 2038;

    public static function isLifetime(?Carbon $until): bool
    {
        return $until !== null && $until->year >= self::LIFETIME_YEAR;
    }

    public function __construct(
        private readonly PlanService $plans,
        private readonly CoinService $coins,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * Read fresh every time: the person's own badge date is already in memory, and the plan half
     * comes from PlanService's memo, so this costs nothing to recompute. Memoising it here as
     * well would only hand back a stale answer after the column was written from somewhere else
     * in the same request.
     */
    public function isVerified(User $user): bool
    {
        return ($user->verified_until?->isFuture() ?? false) || $this->plans->hasBenefit($user, 'verified_badge');
    }

    public function price(): int
    {
        return max(0, (int) AppSetting::get('badge_coin_price'));
    }

    /** 0 = lifetime. */
    public function days(): int
    {
        return max(0, (int) AppSetting::get('badge_days'));
    }

    /** The plan half is what is remembered; drop it after a write that can change the badge. */
    public function forget(User $user): void
    {
        $this->plans->forget($user);
    }

    public function state(User $user): array
    {
        $plan = $this->plans->hasBenefit($user, 'verified_badge');
        $own = $user->verified_until?->isFuture() ?? false;
        $lifetime = $own && self::isLifetime($user->verified_until);

        return [
            'verified' => $plan || $own,
            'source' => $plan ? 'plan' : ($own ? ($user->verified_source ?: 'coins') : null),
            'until' => $own && ! $lifetime ? $user->verified_until->toIso8601String() : null,
            'lifetime' => $lifetime,
            'purchasable' => $this->price() > 0 && ! $lifetime,
            'price' => $this->price(),
            'days' => $this->days(),
        ];
    }

    /**
     * Buy (or extend) the badge with coins. The ledger key carries the client token, so a
     * double tap charges once and returns the same result; a new token extends the period.
     */
    public function buy(User $user, string $clientToken): User
    {
        $price = $this->price();
        if ($price <= 0) {
            throw new PaymentException('badge_unavailable', 'The verified badge is not for sale.', 404);
        }

        return DB::transaction(function () use ($user, $clientToken, $price) {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if (self::isLifetime($user->verified_until)) {
                throw new PaymentException('already_lifetime', 'You already have a lifetime verified badge.', 409);
            }

            $key = "badge:{$user->getKey()}:{$clientToken}";
            if ($this->coins->findByKey($key)) {
                return $user; // The same tap again: already charged and applied.
            }

            $this->coins->debit($user, $price, 'badge', $user, $key, 'Verified badge');
            $user->forceFill(['verified_until' => $this->extendedUntil($user, $this->days()), 'verified_source' => 'coins'])->save();
            $this->forget($user);

            DB::afterCommit(fn () => $user->notify(new MoneyNotification('badge_activated', [
                'title' => 'You are verified',
                'body' => $this->days() > 0 ? 'Your verified badge is on until '.$user->verified_until->format('j M Y').'.' : 'Your verified badge is on for good.',
                'tab' => 'premium',
            ])));

            return $user;
        });
    }

    /** An admin gives the badge for N days (null = lifetime); audited. */
    public function grant(User $admin, User $user, ?int $days): User
    {
        $user->forceFill(['verified_until' => $this->extendedUntil($user, $days === null ? 0 : max(1, $days)), 'verified_source' => 'admin'])->save();
        $this->forget($user);
        $this->audit->record($admin, 'badge.granted', $user, sprintf('Granted the verified badge to %s (%s)', $user->name, $days === null ? 'lifetime' : $days.' days'), ['days' => $days]);

        return $user;
    }

    /** An admin takes a coin/admin badge away (a plan badge ends with the plan); audited. */
    public function remove(User $admin, User $user): User
    {
        $user->forceFill(['verified_until' => null, 'verified_source' => null])->save();
        $this->forget($user);
        $this->audit->record($admin, 'badge.removed', $user, "Removed the verified badge from {$user->name}");

        return $user;
    }

    /** Where the new period ends: after the current one when it is still running, else from now. */
    private function extendedUntil(User $user, int $days): Carbon
    {
        if ($days <= 0) {
            return Carbon::parse(self::LIFETIME);
        }
        $from = $user->verified_until && $user->verified_until->isFuture() ? $user->verified_until->copy() : now();

        return $from->addDays($days);
    }
}
