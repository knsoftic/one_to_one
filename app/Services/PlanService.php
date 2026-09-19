<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\MoneyNotification;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plans and subscriptions (Y2). Entitlements always read the subscription's benefits snapshot,
 * never the plan itself, so an admin editing a plan never changes what a running subscriber has.
 *
 * Every write locks the person's `users` row first, so a payment webhook, the hourly expiry and an
 * admin grant can never interleave on the same person. `users.plan_id` / `plan_until` are a
 * denormalised mirror of the active row, written only here.
 */
class PlanService
{
    /** "Ends in 3 days" reminder window. */
    public const REMIND_DAYS = 3;

    /** @var array<int, ?Subscription> memoised per request */
    private array $active = [];

    public function __construct(
        private readonly CoinService $coins,
        private readonly AdminAuditService $audit,
    ) {}

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

    /* ------------------------------------------------------------------ */
    /* Activation */
    /* ------------------------------------------------------------------ */

    /**
     * A paid period starts, extends or is queued:
     * - no active subscription → a new `active` row from now for one period;
     * - the same plan is active → its `ends_at` moves one period further (renewal, row kept);
     * - a different plan is active → a `queued` row that starts when the current one ends.
     *
     * Idempotent per payment: a subscription that already belongs to this payment is returned
     * unchanged, so a replayed webhook or a retried fulfilment never gives a second period.
     */
    public function activate(User $user, Plan $plan, string $source, ?Payment $payment = null, ?User $by = null): Subscription
    {
        return $this->apply($user, $plan, $source, $payment, $by, fn (Carbon $from) => $this->addPeriod($from, $plan));
    }

    /** An admin gives a plan for a number of days; audited. */
    public function grant(User $admin, User $user, Plan $plan, int $days): Subscription
    {
        $days = max(1, $days);
        $sub = $this->apply($user, $plan, 'admin', null, $admin, fn (Carbon $from) => $from->copy()->addDays($days));

        $this->audit->record($admin, 'subscription.granted', $sub, "Granted the {$plan->name} plan to {$user->name} for {$days} day(s)", [
            'plan' => $plan->getKey(), 'days' => $days, 'subscription' => $sub->getKey(), 'status' => $sub->status,
        ]);

        return $sub;
    }

    /**
     * End a subscription now (refund, admin, Play void). Clears the person's plan mirror when it
     * points at this plan and starts a queued plan right away rather than leaving a paid-for gap.
     * `$note` is what the admin typed; it goes into the audit row, never into the subscription.
     */
    public function revoke(Subscription $sub, string $reason, ?User $by = null, ?string $note = null): void
    {
        $user = $sub->user;
        if (! $user) {
            return;
        }

        $started = DB::transaction(function () use ($sub, $reason, $by, $user) {
            $this->lockUser($user);
            $row = Subscription::query()->whereKey($sub->getKey())->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['active', 'queued'], true)) {
                return null;
            }

            $wasActive = $row->status === 'active';
            $row->forceFill([
                'status' => 'revoked',
                'ends_at' => now(),
                'ended_by' => $by?->getKey(),
                'end_reason' => mb_substr($reason, 0, 60),
            ])->save();
            $sub->setRawAttributes($row->getAttributes(), true);

            if (! $wasActive) {
                return null;
            }

            $this->clearMirror($user, $row);

            // The next plan was bought to follow this one; pull it forward so the days are not lost.
            return $this->startQueued($user, shiftToNow: true);
        });

        $this->forget($user);

        if ($by) {
            $this->audit->record($by, 'subscription.ended', $sub, "Ended the {$sub->plan?->name} plan of {$user->name}".($note ? ": {$note}" : ''), array_filter([
                'reason' => $reason, 'note' => $note, 'subscription' => $sub->getKey(),
            ]));
        }

        if ($started) {
            $this->notifyStarted($user, $started);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Scheduled: expiry, monthly coins, reminders */
    /* ------------------------------------------------------------------ */

    /**
     * Hourly: active rows past their date become `expired`, queued rows whose day has come start.
     * Returns how many rows changed. System work — no audit rows; the data shows what happened.
     */
    public function expire(): int
    {
        $changed = 0;

        $due = Subscription::query()->where('status', 'active')->where('ends_at', '<=', now())->orderBy('id')->get();
        foreach ($due as $sub) {
            $user = $sub->user;
            if (! $user) {
                continue;
            }

            $result = DB::transaction(function () use ($sub, $user) {
                $this->lockUser($user);
                $row = Subscription::query()->whereKey($sub->getKey())->lockForUpdate()->first();
                if (! $row || $row->status !== 'active' || $row->ends_at->isFuture()) {
                    return null;
                }
                $row->forceFill(['status' => 'expired'])->save();
                $this->clearMirror($user, $row);

                return ['expired' => $row, 'started' => $this->startQueued($user)];
            });

            if (! $result) {
                continue;
            }
            $changed++;
            $this->forget($user);

            if ($result['started']) {
                $changed++;
                $this->notifyStarted($user, $result['started']);
            } else {
                $name = $result['expired']->plan?->name ?? 'plan';
                $user->notify(new MoneyNotification('plan_expired', [
                    'title' => "Your {$name} plan has ended",
                    'body' => 'Renew it to keep no ads, your badge and bigger limits.',
                    'tab' => 'premium',
                ]));
            }
        }

        // Queued rows whose start has come without an active row to expire (e.g. after a revoke).
        $waiting = Subscription::query()->where('status', 'queued')->where('starts_at', '<=', now())->orderBy('id')->get();
        foreach ($waiting as $sub) {
            $user = $sub->user;
            if (! $user) {
                continue;
            }
            $started = DB::transaction(function () use ($user) {
                $this->lockUser($user);
                if (Subscription::query()->where('user_id', $user->getKey())->active()->exists()) {
                    return null;
                }

                return $this->startQueued($user);
            });
            if ($started) {
                $changed++;
                $this->forget($user);
                $this->notifyStarted($user, $started);
            }
        }

        if ($changed) {
            Log::info("chat:expire-plans changed {$changed} subscription(s)");
        }

        return $changed;
    }

    /**
     * Daily: the free coins of every month of an active plan, keyed by the period index counted
     * from `starts_at` (`plan-coins:{sub}:{n}`). Running twice writes nothing new; days that were
     * missed are caught up in one run; a yearly plan gets 12 grants.
     */
    public function grantMonthlyCoins(): int
    {
        $granted = 0;

        $subs = Subscription::query()->where('status', 'active')->where('ends_at', '>', now())->orderBy('id')->get()
            ->filter(fn (Subscription $sub) => (int) $sub->benefit('monthly_coins', 0) > 0);

        foreach ($subs as $sub) {
            $user = $sub->user;
            if (! $user) {
                continue;
            }

            $granted += DB::transaction(function () use ($sub, $user) {
                $row = Subscription::query()->whereKey($sub->getKey())->lockForUpdate()->first();
                if (! $row || $row->status !== 'active') {
                    return 0;
                }
                $count = 0;
                $periods = $this->periodsOf($row);
                while ($row->coins_granted_periods < $periods && $row->starts_at->copy()->addMonthsNoOverflow($row->coins_granted_periods)->lte(now())) {
                    if ($this->grantPeriodCoins($user, $row, $row->coins_granted_periods)) {
                        $count++;
                    }
                    $row->forceFill(['coins_granted_periods' => $row->coins_granted_periods + 1])->save();
                }

                return $count;
            });
        }

        return $granted;
    }

    /** Daily: "your plan ends in 3 days", once per subscription (`reminded_at`). */
    public function remindEnding(): int
    {
        $sent = 0;

        $ending = Subscription::query()->where('status', 'active')
            ->whereNull('reminded_at')
            ->whereBetween('ends_at', [now(), now()->addDays(self::REMIND_DAYS)])
            ->orderBy('id')->get();

        foreach ($ending as $sub) {
            $user = $sub->user;
            if (! $user) {
                continue;
            }
            // Mark first: a crash after the notification must not send it twice tomorrow.
            $updated = Subscription::query()->whereKey($sub->getKey())->whereNull('reminded_at')->update(['reminded_at' => now()]);
            if (! $updated) {
                continue;
            }

            $hasNext = Subscription::query()->where('user_id', $user->getKey())->where('status', 'queued')->exists();
            $name = $sub->plan?->name ?? 'plan';
            $user->notify(new MoneyNotification('plan_ending', [
                'title' => "Your {$name} plan ends on ".$sub->ends_at->format('j M'),
                'body' => $hasNext ? 'Your next plan starts right after it.' : 'Renew now so your benefits carry on without a break.',
                'tab' => 'premium',
            ]));
            $sent++;
        }

        return $sent;
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    /**
     * The one activation routine behind activate() and grant(). `$extend` turns the start of a
     * period into its end (one plan period, or the admin's number of days).
     *
     * @param  Closure(Carbon): Carbon  $extend
     */
    private function apply(User $user, Plan $plan, string $source, ?Payment $payment, ?User $by, Closure $extend): Subscription
    {
        [$sub, $kind] = DB::transaction(function () use ($user, $plan, $source, $payment, $extend) {
            $this->lockUser($user);

            if ($payment) {
                $done = Subscription::query()->where('payment_id', $payment->getKey())->first()
                    ?? Subscription::query()->whereKey(Payment::query()->whereKey($payment->getKey())->value('subscription_id'))->first();
                if ($done) {
                    return [$done, 'repeat'];
                }
            }

            $current = Subscription::query()->where('user_id', $user->getKey())->active()
                ->orderByDesc('ends_at')->lockForUpdate()->first();

            if ($current && (int) $current->plan_id === (int) $plan->getKey()) {
                // Renewal: the same row runs on; the monthly coins keep counting from its start.
                $current->forceFill(['ends_at' => $extend($current->ends_at)])->save();
                $this->setMirror($user, $current);
                $this->linkPayment($payment, $current);

                return [$current, 'renewed'];
            }

            if ($current) {
                // A different plan follows the current one; after any plan already waiting.
                $after = Subscription::query()->where('user_id', $user->getKey())->where('status', 'queued')->max('ends_at');
                $starts = $after ? Carbon::parse($after) : $current->ends_at->copy();
                $queued = $this->newRow($user, $plan, $source, $payment, 'queued', $starts, $extend($starts));
                $this->linkPayment($payment, $queued);

                return [$queued, 'queued'];
            }

            $now = now();
            $active = $this->newRow($user, $plan, $source, $payment, 'active', $now, $extend($now));
            $this->setMirror($user, $active);
            $this->startPeriodZero($user, $active);
            $this->linkPayment($payment, $active);

            return [$active, 'new'];
        });

        $this->forget($user);

        if ($kind === 'repeat') {
            return $sub;
        }

        $name = $plan->name;
        $user->notify(new MoneyNotification('plan_activated', [
            'title' => match ($kind) {
                'renewed' => "Your {$name} plan was extended",
                'queued' => "Your {$name} plan starts on ".$sub->starts_at->format('j M Y'),
                default => "Your {$name} plan is active",
            },
            'body' => $kind === 'queued'
                ? 'It begins as soon as your current plan ends.'
                : 'Until '.$sub->ends_at->format('j M Y').'.',
            'tab' => 'premium',
        ]));

        return $sub;
    }

    private function newRow(User $user, Plan $plan, string $source, ?Payment $payment, string $status, Carbon $starts, Carbon $ends): Subscription
    {
        return Subscription::query()->create([
            'user_id' => $user->getKey(),
            'plan_id' => $plan->getKey(),
            'payment_id' => $payment?->getKey(),
            'status' => $status,
            'source' => in_array($source, Subscription::SOURCES, true) ? $source : 'admin',
            'benefits' => $this->snapshot($plan),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'coins_granted_periods' => 0,
        ]);
    }

    /** The payment remembers the subscription it bought or extended (also what makes a renewal replay-safe). */
    private function linkPayment(?Payment $payment, Subscription $sub): void
    {
        if ($payment) {
            Payment::query()->whereKey($payment->getKey())->update(['subscription_id' => $sub->getKey()]);
            $payment->subscription_id = $sub->getKey();
        }
    }

    /** Free coins for one period of a subscription; false when the key was already granted. */
    private function grantPeriodCoins(User $user, Subscription $sub, int $n): bool
    {
        $coins = (int) $sub->benefit('monthly_coins', 0);
        if ($coins <= 0) {
            return false;
        }
        $name = $sub->plan?->name ?? 'Plan';
        $row = $this->coins->credit($user, $coins, 'plan_coins', $sub, "plan-coins:{$sub->getKey()}:{$n}", "{$name} plan · month ".($n + 1).' coins');

        return $row->wasRecentlyCreated;
    }

    /** How many monthly grants a subscription is worth (a renewed monthly plan is worth more). */
    private function periodsOf(Subscription $sub): int
    {
        $months = $sub->starts_at->diffInMonths($sub->ends_at, true);

        return max(1, (int) ceil(round($months, 4)));
    }

    /**
     * The next queued row becomes active. With `$shiftToNow` its dates move so it starts now and
     * keeps its full length (used after a revoke); otherwise it only starts when its day has come.
     */
    private function startQueued(User $user, bool $shiftToNow = false): ?Subscription
    {
        $next = Subscription::query()->where('user_id', $user->getKey())->where('status', 'queued')
            ->when(! $shiftToNow, fn ($q) => $q->where('starts_at', '<=', now()))
            ->orderBy('starts_at')->lockForUpdate()->first();
        if (! $next) {
            return null;
        }

        if ($shiftToNow) {
            $seconds = (int) $next->starts_at->diffInSeconds($next->ends_at, true);
            $next->forceFill(['starts_at' => now(), 'ends_at' => now()->addSeconds($seconds)]);
        }
        $next->forceFill(['status' => 'active'])->save();
        $this->setMirror($user, $next);
        $this->startPeriodZero($user, $next);

        return $next;
    }

    /** Period 0 of a row that just went active: its first month's coins, counted as granted. */
    private function startPeriodZero(User $user, Subscription $sub): void
    {
        $this->grantPeriodCoins($user, $sub, 0);
        $sub->forceFill(['coins_granted_periods' => max(1, $sub->coins_granted_periods)])->save();
    }

    private function notifyStarted(User $user, Subscription $sub): void
    {
        $user->notify(new MoneyNotification('plan_activated', [
            'title' => 'Your '.($sub->plan?->name ?? 'plan').' plan has started',
            'body' => 'Until '.$sub->ends_at->format('j M Y').'.',
            'tab' => 'premium',
        ]));
    }

    private function lockUser(User $user): void
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->first();
    }

    private function setMirror(User $user, Subscription $sub): void
    {
        User::query()->whereKey($user->getKey())->update(['plan_id' => $sub->plan_id, 'plan_until' => $sub->ends_at]);
        $user->forceFill(['plan_id' => $sub->plan_id, 'plan_until' => $sub->ends_at])->syncOriginalAttributes(['plan_id', 'plan_until']);
    }

    /** Clear the mirror only while it still points at this subscription's plan. */
    private function clearMirror(User $user, Subscription $sub): void
    {
        $affected = User::query()->whereKey($user->getKey())->where('plan_id', $sub->plan_id)->update(['plan_id' => null, 'plan_until' => null]);
        if ($affected) {
            $user->forceFill(['plan_id' => null, 'plan_until' => null])->syncOriginalAttributes(['plan_id', 'plan_until']);
        }
    }

    private function addPeriod(Carbon $from, Plan $plan): Carbon
    {
        return $from->copy()->addMonthsNoOverflow($plan->months());
    }
}
