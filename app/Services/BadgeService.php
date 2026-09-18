<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;

/**
 * The verified badge (Y2): bought with coins (users.verified_until), granted by an admin, or
 * included in the active plan (computed from the subscription snapshot, so it follows the plan).
 *
 * NOTE: buy/grant/remove are implemented by the wallet slice; the reads below are the contract
 * every payload relies on.
 */
class BadgeService
{
    /** Stored for a lifetime badge (badge_days = 0). */
    public const LIFETIME = '2099-12-31 00:00:00';

    /** @var array<int, bool> memoised per request */
    private array $verified = [];

    public function __construct(private readonly PlanService $plans) {}

    public function isVerified(User $user): bool
    {
        $id = $user->getKey();

        if (! array_key_exists($id, $this->verified)) {
            $this->verified[$id] = ($user->verified_until?->isFuture() ?? false) || $this->plans->hasBenefit($user, 'verified_badge');
        }

        return $this->verified[$id];
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

    public function forget(User $user): void
    {
        unset($this->verified[$user->getKey()]);
    }

    public function state(User $user): array
    {
        $plan = $this->plans->hasBenefit($user, 'verified_badge');
        $own = $user->verified_until?->isFuture() ?? false;
        $lifetime = $own && $user->verified_until->year >= 2099;

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

    public function buy(User $user, string $clientToken): User
    {
        throw new \LogicException('BadgeService::buy() is implemented by the wallet slice.');
    }

    public function grant(User $admin, User $user, ?int $days): User
    {
        throw new \LogicException('BadgeService::grant() is implemented by the wallet slice.');
    }

    public function remove(User $admin, User $user): User
    {
        throw new \LogicException('BadgeService::remove() is implemented by the wallet slice.');
    }
}
