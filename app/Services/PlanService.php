<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Plans and subscriptions (Y2). Entitlements always read the subscription's benefits snapshot,
 * never the plan itself, so an admin editing a plan never changes what a running subscriber has.
 *
 * NOTE: activation, expiry, renewal, queued plans and monthly coins are implemented by the plans
 * slice; the methods below are the contract every other service relies on.
 */
class PlanService
{
    /** @var array<int, ?Subscription> memoised per request */
    private array $active = [];

    public function purchasable(): Collection
    {
        return Plan::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
    }

    /** The person's current active subscription, if any (memoised per request). */
    public function activeFor(User $user): ?Subscription
    {
        $id = $user->getKey();

        if (! array_key_exists($id, $this->active)) {
            $this->active[$id] = $user->plan_until && $user->plan_until->isPast()
                ? null
                : Subscription::query()->where('user_id', $id)->active()->orderByDesc('ends_at')->first();
        }

        return $this->active[$id];
    }

    /** The benefits snapshot of the active subscription, or [] when there is none. */
    public function benefits(User $user): array
    {
        return $this->activeFor($user)?->benefits ?? [];
    }

    /** ads_off | verified_badge */
    public function hasBenefit(User $user, string $key): bool
    {
        return (bool) ($this->benefits($user)[$key] ?? false);
    }

    /** What a subscription copies at purchase. */
    public function snapshot(Plan $plan): array
    {
        return $plan->benefits();
    }

    /** Forget the memoised subscription after a change. */
    public function forget(User $user): void
    {
        unset($this->active[$user->getKey()]);
    }

    public function activate(User $user, Plan $plan, string $source, ?Payment $payment = null, ?User $by = null): Subscription
    {
        throw new \LogicException('PlanService::activate() is implemented by the plans slice.');
    }

    public function grant(User $admin, User $user, Plan $plan, int $days): Subscription
    {
        throw new \LogicException('PlanService::grant() is implemented by the plans slice.');
    }

    public function revoke(Subscription $sub, string $reason, ?User $by = null): void
    {
        throw new \LogicException('PlanService::revoke() is implemented by the plans slice.');
    }

    public function expire(): int
    {
        throw new \LogicException('PlanService::expire() is implemented by the plans slice.');
    }

    public function grantMonthlyCoins(): int
    {
        throw new \LogicException('PlanService::grantMonthlyCoins() is implemented by the plans slice.');
    }

    public function remindEnding(): int
    {
        throw new \LogicException('PlanService::remindEnding() is implemented by the plans slice.');
    }
}
