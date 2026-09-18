<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Refer & earn (Y2): every account has a code and a link; a friend who signs up with it and
 * verifies their number earns the inviter coins — once, and only after the checks pass.
 *
 * NOTE: implemented by the wallet slice; `rewardIfEligible()` is called from User::booted() on
 * the first phone verification, so it must exist and be a safe no-op until then.
 */
class ReferralService
{
    public function __construct(private readonly MonetisationService $money) {}

    public function enabled(): bool
    {
        return $this->money->referralEnabled();
    }

    public function codeFor(User $user): string
    {
        throw new \LogicException('ReferralService::codeFor() is implemented by the wallet slice.');
    }

    public function link(User $user): string
    {
        return route('referral.join', ['code' => $this->codeFor($user)]);
    }

    public function resolve(?string $code): ?User
    {
        return null;
    }

    public function attach(User $newUser, ?string $code, Request $request): ?Referral
    {
        return null;
    }

    /** The only place referral rewards are paid. */
    public function rewardIfEligible(User $referred): ?Referral
    {
        return null;
    }

    public function void(Referral $referral, string $reason, ?User $by = null): void
    {
        throw new \LogicException('ReferralService::void() is implemented by the wallet slice.');
    }

    public function summary(User $user): array
    {
        throw new \LogicException('ReferralService::summary() is implemented by the wallet slice.');
    }
}
