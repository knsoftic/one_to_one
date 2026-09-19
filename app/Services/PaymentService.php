<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\AppSetting;
use App\Models\CoinPack;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\MoneyNotification;
use App\Services\Payments\ManualGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PayPalGateway;
use App\Services\Payments\PlayGateway;
use App\Services\Payments\StripeGateway;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Payments for plans and coin packs (Y2) through a manual transfer, Stripe, PayPal or Google
 * Play. Which methods a person may use is decided here, on the server, from the platform the
 * request comes from: the Android app gets Google Play only (Play policy), the web gets the rest.
 *
 * States: pending → review (manual, proof uploaded) → paid (money taken) → fulfilled (coins
 * credited / plan activated), plus failed, rejected, cancelled and refunded. Every transition
 * locks the payment row, and delivery goes through the coin ledger's idempotency keys
 * (`payment:{id}`, `payment:{id}:refund`), so a replayed webhook or a double approval never
 * credits twice. Screenshots live on the private `local` disk under payments/{id}/.
 */
class PaymentService
{
    /** Stripe / PayPal checkouts nobody finished are cancelled after this long. */
    public const ABANDON_HOURS = 24;

    public function __construct(private readonly MonetisationService $money) {}

    /** The driver for a gateway key. */
    public function driver(string $gateway): PaymentGateway
    {
        return match ($gateway) {
            'manual' => app(ManualGateway::class),
            'stripe' => app(StripeGateway::class),
            'paypal' => app(PayPalGateway::class),
            'play' => app(PlayGateway::class),
            default => throw new PaymentException('gateway_unavailable', 'Unknown payment method.', 422),
        };
    }

    /**
     * Start a payment. Refuses a gateway that is not offered on this platform (the app gets
     * Google Play only, the web gets the rest), an inactive item, and a PayPal purchase of an item
     * without a US-dollar price. A repeated `client_token` returns the same pending payment.
     *
     * @return array{payment: Payment, redirect: ?string, instructions: ?array}
     */
    public function begin(User $user, string $purpose, Plan|CoinPack $item, string $gateway, string $clientToken, Request $request): array
    {
        if (! $this->money->enabled()) {
            throw new PaymentException('disabled', 'Paid features are switched off.', 404);
        }
        if (! in_array($purpose, Payment::PURPOSES, true) || ($purpose === 'plan') !== ($item instanceof Plan)) {
            throw new PaymentException('invalid_item', 'That item cannot be bought.', 422);
        }
        if (! in_array($gateway, $this->methodsFor($user, $request, $item), true)) {
            throw new PaymentException('gateway_unavailable', 'This payment method is not available here.', 422);
        }
        if (! $item->is_active) {
            throw new PaymentException('item_unavailable', 'This item is no longer for sale.', 422);
        }
        if ($gateway === 'play') {
            // Play purchases start in the app's Billing sheet and reach the server through pay.play.verify.
            throw new PaymentException('use_play', 'In the app, buy through Google Play.', 422);
        }

        // A double tap (same client token) gets the same pending payment back.
        $existing = Payment::query()->where('client_token', $clientToken)->where('user_id', $user->getKey())->first();
        if ($existing) {
            if ($existing->status !== 'pending') {
                throw new PaymentException('already_used', 'This payment was already started. Refresh and try again.', 409);
            }

            return $this->beginResult($existing, ['redirect' => $existing->meta['redirect'] ?? null]);
        }

        // Older unfinished attempts at the same thing are superseded (review ones are kept).
        Payment::query()->where('user_id', $user->getKey())->where('status', 'pending')
            ->where('gateway', $gateway)->where('purpose', $purpose)
            ->where($purpose === 'plan' ? 'plan_id' : 'coin_pack_id', $item->getKey())
            ->get()->each(fn (Payment $old) => $this->cancel($old, 'superseded'));

        $usd = $gateway === 'paypal';
        $payment = Payment::query()->create([
            'uuid' => (string) Str::uuid(),
            'client_token' => $clientToken,
            'user_id' => $user->getKey(),
            'purpose' => $purpose,
            'plan_id' => $item instanceof Plan ? $item->getKey() : null,
            'coin_pack_id' => $item instanceof CoinPack ? $item->getKey() : null,
            'gateway' => $gateway,
            'status' => 'pending',
            'platform' => $this->money->platform($request),
            'amount_minor' => $usd ? (int) $item->price_usd_minor : (int) $item->price_minor,
            'currency' => $usd ? 'USD' : strtoupper((string) $item->currency),
            'coins' => $item instanceof CoinPack ? $item->totalCoins() : null,
        ]);

        try {
            $out = $this->driver($gateway)->start($payment);
        } catch (PaymentException $e) {
            $this->fail($payment, 'gateway_error', ['error' => $e->getMessage()]);
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->fail($payment, 'gateway_error', ['error' => Str::limit($e->getMessage(), 200)]);
            throw new PaymentException('gateway_error', 'The payment provider did not answer. Try again in a moment.', 503);
        }

        if (! empty($out['redirect'])) {
            $payment->forceFill(['meta' => array_merge($payment->meta ?? [], ['redirect' => $out['redirect']])])->save();
        }

        return $this->beginResult($payment, $out);
    }

    /** @return array{payment: Payment, redirect: ?string, instructions: ?array} */
    private function beginResult(Payment $payment, array $out): array
    {
        return [
            'payment' => $payment,
            'redirect' => $out['redirect'] ?? null,
            'instructions' => $payment->gateway === 'manual' ? $this->manualInstructions($payment) : ($out['instructions'] ?? null),
        ];
    }

    /** What the manual-payment sheet shows: where to send the money and how much. */
    public function manualInstructions(Payment $payment): array
    {
        return [
            'methods' => $this->manualMethods(),
            'note' => trim((string) AppSetting::get('manual_note')) ?: null,
            'amount' => $payment->amount_minor,
            'currency' => $payment->currency,
            'amount_display' => $this->money->formatMoney($payment->amount_minor, $payment->currency),
        ];
    }

    /** The JSON shape of a payment for the wallet / premium screens and pay.show polling. */
    public function payload(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'uuid' => $payment->uuid,
            'status' => $payment->status,
            'gateway' => $payment->gateway,
            'purpose' => $payment->purpose,
            'plan_id' => $payment->plan_id,
            'coin_pack_id' => $payment->coin_pack_id,
            'item' => $payment->itemLabel(),
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'amount_display' => $this->money->formatMoney($payment->amount_minor, $payment->currency),
            'coins' => $payment->coins,
            'manual_method' => $payment->manual_method,
            'proof_ref' => $payment->proof_ref,
            'has_proof' => $payment->proof_path !== null,
            'review_note' => $payment->review_note,
            'reason' => $payment->meta['reason'] ?? null,
            'redirect' => $payment->status === 'pending' ? ($payment->meta['redirect'] ?? null) : null,
            'created_at' => $payment->created_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'fulfilled_at' => $payment->fulfilled_at?->toIso8601String(),
        ];
    }

    /** The person uploads the transfer screenshot: manual pending → review. */
    public function submitProof(Payment $payment, string $method, string $ref, ?string $note, UploadedFile $shot): Payment
    {
        if ($payment->gateway !== 'manual') {
            throw new PaymentException('not_manual', 'This payment does not need a screenshot.', 422);
        }
        if ($payment->status !== 'pending') {
            throw new PaymentException('not_pending', 'This payment is no longer waiting for a screenshot.', 409);
        }
        if (! array_key_exists($method, $this->manualMethods())) {
            throw new PaymentException('unknown_method', 'Choose one of the listed payment methods.', 422);
        }

        if ($payment->proof_path) {
            Storage::disk('local')->delete($payment->proof_path);
        }
        $path = $shot->store('payments/'.$payment->id, 'local');

        $payment->forceFill([
            'status' => 'review',
            'manual_method' => $method,
            'proof_ref' => mb_substr(trim($ref), 0, 64),
            'proof_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 160) : null,
            'proof_path' => $path,
        ])->save();

        return $payment;
    }

    /**
     * The money was taken: pending|review → paid. Idempotent when already paid or fulfilled; a
     * final payment (cancelled, failed, …) cannot be paid any more.
     *
     * @param  array{gateway_ref?: string, gateway_capture_ref?: string, meta?: array}  $gateway
     */
    public function markPaid(Payment $payment, array $gateway): Payment
    {
        return DB::transaction(function () use ($payment, $gateway) {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();
            if (in_array($locked->status, ['paid', 'fulfilled'], true)) {
                return $this->copy($payment, $locked);
            }
            if (! in_array($locked->status, ['pending', 'review'], true)) {
                throw new PaymentException('invalid_state', "This payment is {$locked->status} and cannot be marked paid.", 409);
            }

            $locked->forceFill(array_filter([
                'gateway_ref' => $gateway['gateway_ref'] ?? null,
                'gateway_capture_ref' => $gateway['gateway_capture_ref'] ?? null,
            ]) + [
                'status' => 'paid',
                'paid_at' => now(),
                'meta' => array_merge($locked->meta ?? [], $gateway['meta'] ?? []) ?: null,
            ])->save();

            return $this->copy($payment, $locked);
        }, attempts: 3);
    }

    /**
     * Deliver what was bought: paid → fulfilled. Coins go through the ledger with key
     * `payment:{id}`; a plan goes through PlanService::activate() (idempotent per payment).
     */
    public function fulfil(Payment $payment): Payment
    {
        $notify = null;

        DB::transaction(function () use ($payment, &$notify) {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();
            if ($locked->status === 'fulfilled') {
                $this->copy($payment, $locked);

                return;
            }
            if ($locked->status !== 'paid') {
                throw new PaymentException('not_paid', "This payment is {$locked->status}, not paid.", 409);
            }
            $user = $locked->user;
            if (! $user) {
                throw new PaymentException('no_user', 'The account this payment belongs to no longer exists.', 409);
            }

            if ($locked->purpose === 'coins') {
                $coins = (int) $locked->coins;
                if ($coins <= 0) {
                    throw new PaymentException('no_coins', 'This payment has no coins to deliver.', 409);
                }
                $this->coins()->credit($user, $coins, 'purchase', $locked, "payment:{$locked->id}", 'Bought '.number_format($coins).' coins');
                $notify = ['coins_credited', ['title' => number_format($coins).' coins added', 'body' => 'Your payment went through. Enjoy!', 'tab' => 'wallet']];
            } else {
                $plan = $locked->plan;
                if (! $plan) {
                    throw new PaymentException('no_plan', 'The plan of this payment no longer exists.', 409);
                }
                $sub = $this->plans()->activate($user, $plan, $locked->gateway, $locked);
                $locked->subscription_id = $sub->getKey();
                if ($locked->gateway === 'manual') {
                    // Card / PayPal / Play buyers are told by PlanService (plan_activated); a manual buyer waited for this.
                    $notify = ['payment_approved', ['title' => 'Payment approved', 'body' => "{$plan->name} is now active on your account.", 'tab' => 'premium']];
                }
            }

            $locked->forceFill(['status' => 'fulfilled', 'fulfilled_at' => now()])->save();
            $this->copy($payment, $locked);
        }, attempts: 3);

        if ($notify && $payment->user) {
            if ($payment->gateway === 'manual' && $notify[0] === 'coins_credited') {
                $notify[1]['title'] = 'Payment approved · '.$notify[1]['title'];
            }
            $payment->user->notify(new MoneyNotification($notify[0], $notify[1]));
        }

        return $payment;
    }

    /** markPaid then fulfil. A delivery failure is reported and leaves the payment `paid` (admin → Retry). */
    public function settle(Payment $payment, array $gateway): Payment
    {
        $this->markPaid($payment, $gateway);

        try {
            $this->fulfil($payment);
        } catch (Throwable $e) {
            report($e);
            Log::warning("Payment #{$payment->id} was paid but not delivered: ".$e->getMessage());
            Payment::query()->whereKey($payment->getKey())->where('status', 'paid')
                ->update(['meta' => json_encode(array_merge($payment->meta ?? [], ['fulfil_error' => Str::limit($e->getMessage(), 200)]))]);
            $payment->refresh();
        }

        return $payment;
    }

    /** Admin approves a manual transfer under review. */
    public function approve(Payment $payment, User $admin, ?string $note): Payment
    {
        if ($payment->gateway !== 'manual') {
            throw new PaymentException('not_manual', 'Only manual transfers are approved by hand.', 422);
        }
        if ($payment->status !== 'review') {
            throw new PaymentException('not_in_review', "This payment is {$payment->status}, not waiting for review.", 409);
        }

        $payment->forceFill([
            'reviewed_by' => $admin->getKey(),
            'reviewed_at' => now(),
            'review_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null,
        ])->save();

        $this->settle($payment, ['gateway_ref' => 'manual:'.$payment->id]);

        $this->audit()->record($admin, 'payment.approved', $payment, sprintf('Approved payment #%d (%s, %s) for %s', $payment->id, $payment->itemLabel(), $this->money->formatMoney($payment->amount_minor, $payment->currency), $payment->user?->name ?? 'a deleted account'), array_filter([
            'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency, 'purpose' => $payment->purpose, 'note' => $note,
            'delivered' => $payment->status === 'fulfilled',
        ]));

        return $payment;
    }

    /** Admin rejects a manual transfer (the note is shown to the person). */
    public function reject(Payment $payment, User $admin, string $note): Payment
    {
        if (! in_array($payment->status, ['review', 'pending'], true)) {
            throw new PaymentException('not_in_review', "This payment is {$payment->status} and cannot be rejected.", 409);
        }

        $payment->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $admin->getKey(),
            'reviewed_at' => now(),
            'review_note' => mb_substr(trim($note), 0, 200),
            'meta' => array_merge($payment->meta ?? [], ['reason' => 'rejected']),
        ])->save();

        $this->audit()->record($admin, 'payment.rejected', $payment, sprintf('Rejected payment #%d (%s) for %s: %s', $payment->id, $payment->itemLabel(), $payment->user?->name ?? 'a deleted account', $note), ['note' => $note]);

        $payment->user?->notify(new MoneyNotification('payment_rejected', [
            'title' => 'Payment not approved',
            'body' => 'Not approved: '.$payment->review_note.' You can try again.',
            'tab' => $payment->purpose === 'plan' ? 'premium' : 'wallet',
        ]));

        return $payment;
    }

    /** The provider says the money did not come (or does not match). Final; no-op when already final. */
    public function fail(Payment $payment, string $reason, array $meta = []): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $meta) {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();
            if ($locked->isFinal()) {
                return $this->copy($payment, $locked);
            }
            $locked->forceFill(['status' => 'failed', 'meta' => array_merge($locked->meta ?? [], $meta, ['reason' => $reason])])->save();
            Log::info("Payment #{$locked->id} failed: {$reason}");

            return $this->copy($payment, $locked);
        }, attempts: 3);
    }

    /** Nobody finished this: pending → cancelled. Anything else is left alone. */
    public function cancel(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();
            if ($locked->status !== 'pending') {
                return $this->copy($payment, $locked);
            }
            $locked->forceFill(['status' => 'cancelled', 'meta' => array_merge($locked->meta ?? [], ['reason' => $reason])])->save();

            return $this->copy($payment, $locked);
        }, attempts: 3);
    }

    /**
     * The money went back: fulfilled|paid → refunded. Coins are clawed back (what is missing is
     * recorded as `meta.shortfall`); a plan is revoked. `$viaGateway` asks Stripe/PayPal for the
     * refund first — when that throws nothing changes.
     */
    public function reverse(Payment $payment, string $reason, ?User $admin = null, ?string $note = null, bool $viaGateway = false): Payment
    {
        if ($payment->status === 'refunded') {
            return $payment;
        }
        if (! in_array($payment->status, ['fulfilled', 'paid'], true)) {
            throw new PaymentException('not_refundable', "This payment is {$payment->status} and cannot be refunded.", 409);
        }

        $refundRef = $viaGateway ? $this->driver($payment->gateway)->refund($payment, $note) : null;

        $shortfall = 0;
        DB::transaction(function () use ($payment, $reason, $admin, $note, $refundRef, &$shortfall) {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->first();
            if ($locked->status === 'refunded') {
                $this->copy($payment, $locked);

                return;
            }
            $user = $locked->user;

            if ($locked->status === 'fulfilled') {
                if ($locked->purpose === 'coins' && (int) $locked->coins > 0) {
                    if ($user) {
                        $row = $this->coins()->clawback($user, (int) $locked->coins, 'payment_refund', $locked, "payment:{$locked->id}:refund", 'Payment #'.$locked->id.' refunded');
                        $shortfall = (int) ($row->meta['shortfall'] ?? 0);
                    } else {
                        $shortfall = (int) $locked->coins;
                    }
                } elseif ($locked->purpose === 'plan' && $locked->subscription) {
                    $sub = $locked->subscription;
                    if (in_array($sub->status, ['active', 'queued'], true)) {
                        $this->plans()->revoke($sub, $reason, $admin);
                    }
                }
            }

            $locked->forceFill([
                'status' => 'refunded',
                'refunded_at' => now(),
                'refund_ref' => $refundRef ?? $locked->refund_ref,
                'meta' => array_merge($locked->meta ?? [], array_filter([
                    'reason' => $reason,
                    'refund_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null,
                    'shortfall' => $shortfall ?: null,
                ])),
            ])->save();
            $this->copy($payment, $locked);
        }, attempts: 3);

        if ($admin) {
            $this->audit()->record($admin, 'payment.refunded', $payment, sprintf('Refunded payment #%d (%s) for %s%s', $payment->id, $payment->itemLabel(), $payment->user?->name ?? 'a deleted account', $note ? ': '.$note : ''), array_filter(['reason' => $reason, 'note' => $note, 'shortfall' => $shortfall, 'via_gateway' => $viaGateway]));
        }

        $payment->user?->notify(new MoneyNotification('payment_refunded', [
            'title' => 'Payment refunded',
            'body' => match ($reason) {
                'play_void' => 'Google refunded this purchase. '.($payment->purpose === 'coins' ? 'The coins were taken back.' : 'The plan was ended.'),
                'stripe_refund', 'paypal_refund' => 'Your payment was refunded. '.($payment->purpose === 'coins' ? 'The coins were taken back.' : 'The plan was ended.'),
                default => 'Your payment for '.$payment->itemLabel().' was refunded.'.($payment->purpose === 'coins' ? ' The coins were taken back.' : ' The plan was ended.'),
            },
            'tab' => $payment->purpose === 'plan' ? 'premium' : 'wallet',
        ]));

        return $payment;
    }

    /** Admin retries delivery of a payment that was paid but not fulfilled. Exceptions bubble up. */
    public function retry(Payment $payment, User $admin): Payment
    {
        if ($payment->status !== 'paid') {
            throw new PaymentException('not_paid', "This payment is {$payment->status}; only paid-but-undelivered payments are retried.", 409);
        }

        $this->fulfil($payment);
        $this->audit()->record($admin, 'payment.retried', $payment, sprintf('Retried delivering payment #%d (%s) for %s', $payment->id, $payment->itemLabel(), $payment->user?->name ?? 'a deleted account'), ['delivered' => $payment->status === 'fulfilled']);

        return $payment;
    }

    /** Scheduled: manual payments without a screenshot and abandoned Stripe/PayPal checkouts are cancelled. */
    public function expirePending(): int
    {
        $manualHours = max(1, (int) AppSetting::get('manual_expire_hours'));
        $n = 0;

        Payment::query()->where('status', 'pending')->where(function ($q) use ($manualHours) {
            $q->where(fn ($m) => $m->where('gateway', 'manual')->where('created_at', '<', now()->subHours($manualHours)))
                ->orWhere(fn ($o) => $o->whereIn('gateway', ['stripe', 'paypal'])->where('created_at', '<', now()->subHours(self::ABANDON_HOURS)));
        })->orderBy('id')->get()->each(function (Payment $p) use (&$n) {
            $this->cancel($p, $p->gateway === 'manual' ? 'no_proof' : 'abandoned');
            $n++;
        });

        return $n;
    }

    /** Scheduled: screenshots of settled payments are deleted after `proof_keep_days`. */
    public function purgeProofs(): int
    {
        $days = max(1, (int) AppSetting::get('proof_keep_days'));
        $n = 0;

        Payment::query()->whereNotNull('proof_path')->final()->where('updated_at', '<', now()->subDays($days))
            ->orderBy('id')->get()->each(function (Payment $p) use (&$n) {
                Storage::disk('local')->delete($p->proof_path);
                Storage::disk('local')->deleteDirectory('payments/'.$p->id);
                $p->forceFill(['proof_path' => null])->save();
                $n++;
            });

        return $n;
    }

    /** Account deletion: screenshots go, amounts stay for accounting (user_id nulls through the FK). */
    public function anonymiseFor(User $user): void
    {
        Payment::query()->where('user_id', $user->getKey())->orderBy('id')->get()->each(function (Payment $p) {
            if ($p->proof_path) {
                Storage::disk('local')->delete($p->proof_path);
                Storage::disk('local')->deleteDirectory('payments/'.$p->id);
            }
            $p->forceFill(['proof_path' => null, 'proof_note' => null, 'meta' => array_merge($p->meta ?? [], ['deleted_user' => true])])->save();
        });
    }

    /** Remember a provider callback before applying it; race-safe on the (gateway, event id) unique index. */
    public function recordEvent(string $gateway, string $eventId, string $type, array $payload, ?Payment $payment): PaymentEvent
    {
        $attributes = ['gateway' => $gateway, 'event_id' => mb_substr($eventId, 0, 191)];
        $values = ['type' => mb_substr($type, 0, 80), 'payment_id' => $payment?->getKey(), 'payload' => $payload ?: null, 'created_at' => now()];

        try {
            return PaymentEvent::query()->firstOrCreate($attributes, $values);
        } catch (UniqueConstraintViolationException) {
            return PaymentEvent::query()->where($attributes)->firstOrFail();
        }
    }

    /**
     * Apply a recorded event exactly once: lock the row, skip when `processed_at` is set, run the
     * handler and stamp it in the same transaction. A throwing handler rolls everything back,
     * stores the error on the row and rethrows (the caller answers 500 so the provider retries).
     *
     * @param  Closure(PaymentEvent): void  $handler
     */
    public function processEvent(PaymentEvent $event, Closure $handler): void
    {
        try {
            DB::transaction(function () use ($event, $handler) {
                $locked = PaymentEvent::query()->whereKey($event->getKey())->lockForUpdate()->first();
                if ($locked->processed_at) {
                    $event->processed_at = $locked->processed_at;

                    return;
                }
                $handler($locked);
                $locked->forceFill(['processed_at' => now(), 'error' => null])->save();
                $event->setRawAttributes($locked->getAttributes());
            }, attempts: 1);
        } catch (Throwable $e) {
            PaymentEvent::query()->whereKey($event->getKey())->update(['error' => Str::limit($e->getMessage(), 290)]);
            throw $e;
        }
    }

    /** True when the event was applied before (a duplicate delivery). */
    public function isProcessed(PaymentEvent $event): bool
    {
        return $event->processed_at !== null;
    }

    /** Copy the locked row's attributes onto the caller's instance so it sees the new state. */
    private function copy(Payment $target, Payment $locked): Payment
    {
        $target->setRawAttributes($locked->getAttributes(), true);
        $target->exists = true;

        return $target;
    }

    private function coins(): CoinService
    {
        return app(CoinService::class);
    }

    private function plans(): PlanService
    {
        return app(PlanService::class);
    }

    private function audit(): AdminAuditService
    {
        return app(AdminAuditService::class);
    }

    /**
     * The gateways offered to this person for this item, in display order.
     *
     * @return list<string>
     */
    public function methodsFor(User $user, Request $request, Plan|CoinPack|null $item = null): array
    {
        if (! $this->money->enabled()) {
            return [];
        }

        if ($this->money->platform($request) === 'android') {
            $playOk = (bool) AppSetting::get('play_enabled') && ($item === null || filled($item->play_product_id));

            return $playOk ? ['play'] : [];
        }

        $methods = [];
        if ((bool) AppSetting::get('manual_enabled') && $this->manualMethods()) {
            $methods[] = 'manual';
        }
        if ((bool) AppSetting::get('stripe_enabled') && filled(config('services.stripe.secret'))) {
            $methods[] = 'stripe';
        }
        if ((bool) AppSetting::get('paypal_enabled') && filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret'))
            && ($item === null || (int) $item->price_usd_minor > 0)) {
            $methods[] = 'paypal';
        }

        return $methods;
    }

    /** The manual transfer methods the admin wrote instructions for. */
    public function manualMethods(): array
    {
        $out = [];
        foreach (['jazzcash' => 'JazzCash', 'easypaisa' => 'EasyPaisa', 'bank' => 'Bank transfer'] as $key => $label) {
            $text = trim((string) AppSetting::get('manual_'.$key));
            if ($text !== '') {
                $out[$key] = ['label' => $label, 'text' => $text];
            }
        }

        return $out;
    }
}
