<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\MoneyNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refer & earn (Y2): every account has a code and a link; a friend who signs up with it and
 * verifies their number earns the inviter coins — once, and only after the checks pass.
 *
 * `attach()` runs at sign-up and only ever writes a `pending` row. `rewardIfEligible()` is the
 * only place coins are paid; it is called from User::booted() the first time
 * `phone_verified_at` goes from null to a date, so it must stay a safe no-op for everyone else.
 */
class ReferralService
{
    /** Letters and digits that are never confused with each other (no 0/O, 1/I). */
    public const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ23456789';

    /** A referred account deleted this soon after being rewarded takes the reward back. */
    public const CLAWBACK_DAYS = 7;

    public function __construct(
        private readonly MonetisationService $money,
        private readonly CoinService $coins,
        private readonly AdminAuditService $audit,
    ) {}

    public function enabled(): bool
    {
        return $this->money->referralEnabled();
    }

    /** The person's code, made on first use and kept for good. */
    public function codeFor(User $user): string
    {
        if (filled($user->referral_code)) {
            return $user->referral_code;
        }

        for ($try = 0; $try < 10; $try++) {
            $code = self::randomCode();
            try {
                $user->forceFill(['referral_code' => $code])->save();

                return $code;
            } catch (UniqueConstraintViolationException) {
                // Somebody else got this code first — try another one.
                $user->forceFill(['referral_code' => null])->syncOriginalAttribute('referral_code');
            }
        }

        throw new \RuntimeException('Could not make a unique referral code.');
    }

    public static function randomCode(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    public function link(User $user): string
    {
        return route('referral.join', ['code' => $this->codeFor($user)]);
    }

    /** The active, non-banned owner of a code. */
    public function resolve(?string $code): ?User
    {
        $code = strtoupper(trim((string) $code));
        if (! preg_match('/^[A-Z2-9]{8}$/', $code)) {
            return null;
        }

        $owner = User::query()->where('referral_code', $code)->first();

        return $owner && $owner->isActive() && ! $owner->isBanned() ? $owner : null;
    }

    /**
     * At sign-up only: remember who invited this person. Never pays anything. Quietly does
     * nothing when referrals are off, the code is unknown, or someone is inviting themself.
     */
    public function attach(User $newUser, ?string $code, Request $request): ?Referral
    {
        if (! $this->enabled()) {
            return null;
        }
        $referrer = $this->resolve($code);
        if (! $referrer || $referrer->is($newUser) || $this->samePhone($referrer, $newUser)) {
            return null;
        }
        if (Referral::query()->where('referred_id', $newUser->getKey())->exists()) {
            return null;
        }

        try {
            $referral = Referral::query()->create([
                'referrer_id' => $referrer->getKey(),
                'referred_id' => $newUser->getKey(),
                'code' => $referrer->referral_code,
                'status' => 'pending',
                'ip_hash' => self::ipHash($request),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $newUser->forceFill(['referred_by' => $referrer->getKey()])->save();

        return $referral;
    }

    /**
     * The ONLY place referral rewards are paid: when the referred person verifies their number.
     * Locks the referral row, so two verifications at once still pay once. A pending row that
     * fails a check becomes `void` with the reason, so the admin can see why nothing was paid.
     */
    public function rewardIfEligible(User $referred): ?Referral
    {
        return DB::transaction(function () use ($referred) {
            $referral = Referral::query()->where('referred_id', $referred->getKey())->lockForUpdate()->first();
            if (! $referral || $referral->status !== 'pending') {
                return $referral;
            }

            $referrer = $referral->referrer;
            $reason = match (true) {
                ! $this->enabled() => 'disabled',
                ! $referrer || ! $referrer->isActive() || $referrer->isBanned() => 'referrer_inactive',
                $referrer->getKey() === $referred->getKey() || $this->samePhone($referrer, $referred) => 'self',
                $this->ipCapReached($referral) => 'ip_cap',
                $this->dailyCapReached($referrer) => 'daily_cap',
                default => null,
            };
            if ($reason !== null) {
                $this->void($referral, $reason);

                return $referral;
            }

            $reward = max(0, (int) AppSetting::get('referral_reward'));
            $welcome = max(0, (int) AppSetting::get('referral_welcome'));

            if ($reward > 0) {
                $this->coins->credit($referrer, $reward, 'referral', $referral, "referral:{$referral->id}:referrer", "Invited {$referred->name}", withdrawable: true);
            }
            if ($welcome > 0) {
                $this->coins->credit($referred, $welcome, 'referral_welcome', $referral, "referral:{$referral->id}:referred", 'Welcome coins');
            }

            $referral->forceFill([
                'status' => 'rewarded',
                'referrer_coins' => $reward,
                'referred_coins' => $welcome,
                'rewarded_at' => now(),
            ])->save();

            DB::afterCommit(function () use ($referrer, $referred, $reward, $welcome) {
                if ($reward > 0) {
                    $referrer->notify(new MoneyNotification('referral_rewarded', [
                        'title' => "You earned {$reward} coins",
                        'body' => "{$referred->name} joined with your invite and verified their number.",
                        'tab' => 'refer',
                    ]));
                }
                if ($welcome > 0) {
                    $referred->notify(new MoneyNotification('referral_rewarded', [
                        'title' => "Welcome! You got {$welcome} coins",
                        'body' => "A gift for joining through {$referrer->name}'s invite.",
                        'tab' => 'wallet',
                    ]));
                }
            });

            return $referral;
        });
    }

    /**
     * Reverse a referral. A rewarded one takes the coins back from both sides (as far as the
     * wallets allow — a shortfall is kept on the ledger rows); a pending one is just marked.
     */
    public function void(Referral $referral, string $reason, ?User $by = null, ?string $note = null): void
    {
        DB::transaction(function () use ($referral, $reason, $by, $note) {
            $referral = Referral::query()->whereKey($referral->getKey())->lockForUpdate()->first();
            if (! $referral || $referral->status === 'void') {
                return;
            }
            $label = Referral::VOID_REASONS[$reason] ?? $reason;

            if ($referral->status === 'rewarded') {
                if ($referral->referrer_coins > 0 && $referral->referrer) {
                    $this->coins->clawback($referral->referrer, $referral->referrer_coins, 'referral_void', $referral, "referral:{$referral->id}:void:referrer", "Referral reversed: {$label}");
                }
                if ($referral->referred_coins > 0 && $referral->referred) {
                    $this->coins->clawback($referral->referred, $referral->referred_coins, 'referral_void', $referral, "referral:{$referral->id}:void:referred", "Referral reversed: {$label}");
                }
            }

            $referral->forceFill(['status' => 'void', 'void_reason' => $reason, 'voided_at' => now()])->save();

            if ($by) {
                $this->audit->record($by, 'referral.voided', $referral, sprintf('Reversed the referral of %s by %s: %s', $referral->referred?->name ?? '#'.$referral->referred_id, $referral->referrer?->name ?? '#'.$referral->referrer_id, $note ?: $label), [
                    'reason' => $reason, 'note' => $note, 'referrer_coins' => $referral->referrer_coins, 'referred_coins' => $referral->referred_coins,
                ]);
            } else {
                Log::info("Referral #{$referral->id} voided: {$reason}");
            }
        });
    }

    /** AccountDeletionService: an account deleted soon after being rewarded takes the reward back. */
    public function voidForDeletedAccount(User $referred): void
    {
        $referral = Referral::query()->where('referred_id', $referred->getKey())->first();
        if (! $referral) {
            return;
        }
        if ($referral->status === 'rewarded' && $referral->rewarded_at && $referral->rewarded_at->gt(now()->subDays(self::CLAWBACK_DAYS))) {
            $this->void($referral, 'deleted_early');
        } elseif ($referral->status === 'pending') {
            $this->void($referral, 'deleted_early');
        }
    }

    /** What the Refer & earn screen shows. */
    public function summary(User $user): array
    {
        $rows = Referral::query()->where('referrer_id', $user->getKey())->with('referred:id,name')->orderByDesc('id')->limit(100)->get();

        return [
            'code' => $this->codeFor($user),
            'link' => $this->link($user),
            'reward' => max(0, (int) AppSetting::get('referral_reward')),
            'welcome' => max(0, (int) AppSetting::get('referral_welcome')),
            'invited' => $rows->count(),
            'rewarded' => $rows->where('status', 'rewarded')->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'coins_earned' => (int) $rows->where('status', 'rewarded')->sum('referrer_coins'),
            'list' => $rows->map(fn (Referral $r) => [
                'id' => $r->id,
                'name' => $r->referred?->name ?? 'Deleted account',
                'status' => $r->status,
                'coins' => $r->status === 'rewarded' ? $r->referrer_coins : 0,
                'date' => $r->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public static function ipHash(Request $request): string
    {
        return hash('sha256', (string) $request->ip().config('app.key'));
    }

    private function samePhone(User $a, User $b): bool
    {
        return filled($a->phone) && (string) $a->phone === (string) $b->phone;
    }

    /** Rewarded sign-ups from the same connection in the last 7 days. */
    private function ipCapReached(Referral $referral): bool
    {
        $cap = (int) AppSetting::get('referral_ip_cap');
        if ($cap <= 0 || blank($referral->ip_hash)) {
            return false;
        }

        return Referral::query()->where('ip_hash', $referral->ip_hash)->where('status', 'rewarded')
            ->where('created_at', '>=', now()->subDays(7))->count() >= $cap;
    }

    /** Rewards this inviter already got today. */
    private function dailyCapReached(User $referrer): bool
    {
        $cap = (int) AppSetting::get('referral_daily_cap');
        if ($cap <= 0) {
            return false;
        }

        return Referral::query()->where('referrer_id', $referrer->getKey())->where('status', 'rewarded')
            ->where('rewarded_at', '>=', now()->startOfDay())->count() >= $cap;
    }
}
