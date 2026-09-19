<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentException;
use App\Models\AppSetting;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

/**
 * Cards through Stripe's hosted Checkout (Y2): no Stripe.js, no card data here. Money is only
 * credited from the signature-verified webhook or a server-side session retrieve — never from
 * the browser coming back.
 */
class StripeGateway implements PaymentGateway
{
    /** Seconds a webhook signature may be old. */
    public const TOLERANCE = 300;

    /** Seconds between two live session look-ups for the same payment (pay.show polling). */
    public const SYNC_EVERY = 10;

    public function __construct(private readonly PaymentService $payments) {}

    public function key(): string
    {
        return 'stripe';
    }

    public function available(): bool
    {
        return (bool) AppSetting::get('stripe_enabled') && filled(config('services.stripe.secret'));
    }

    /** Create the Checkout Session; the person is sent to Stripe's page. */
    public function start(Payment $payment): array
    {
        $session = $this->createSession([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($payment->currency),
                    'unit_amount' => $payment->amount_minor,
                    'product_data' => ['name' => $payment->itemLabel().' · '.config('app.name')],
                ],
            ]],
            'client_reference_id' => $payment->uuid,
            'metadata' => ['payment_id' => (string) $payment->id],
            'success_url' => route('pay.return', ['payment' => $payment->id, 'gateway' => 'stripe']),
            // Signed: coming back cancelled changes the payment, so only a link we made may do it
            // (a plain GET could otherwise be fired from any page the person visits).
            'cancel_url' => URL::signedRoute('pay.return', ['payment' => $payment->id, 'gateway' => 'stripe', 'cancelled' => 1]),
            'expires_at' => now()->addHours(PaymentService::ABANDON_HOURS)->getTimestamp(),
        ], $payment->uuid);

        $payment->forceFill(['gateway_ref' => $session->id])->save();

        return ['redirect' => (string) $session->url];
    }

    /**
     * A webhook delivery: verify, record, apply once. Throws SignatureVerificationException for a
     * bad signature (the controller answers 400, nothing is written).
     *
     * @return array{status: 'processed'|'duplicate', type: string}
     */
    public function handleWebhook(string $rawBody, string $sigHeader): array
    {
        $event = Webhook::constructEvent($rawBody, $sigHeader, (string) config('services.stripe.webhook_secret'), self::TOLERANCE);

        $object = $event->data->object;
        $record = $this->payments->recordEvent('stripe', $event->id, $event->type, $this->trim($object->toArray()), $this->paymentFor($event->type, $object->toArray()));
        if ($this->payments->isProcessed($record)) {
            return ['status' => 'duplicate', 'type' => $event->type];
        }

        $this->payments->processEvent($record, fn (PaymentEvent $row) => $this->apply($row, $event));

        return ['status' => 'processed', 'type' => $event->type];
    }

    /** Apply one verified event. Unknown payments and other types are simply ignored (processed). */
    public function apply(PaymentEvent $row, Event $event): void
    {
        $object = $event->data->object->toArray();

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $payment = $this->sessionPayment($object);
                if (! $payment) {
                    return;
                }
                $row->payment_id = $payment->id;
                $this->applySession($payment, $object);

                return;
            case 'checkout.session.expired':
                $payment = $this->sessionPayment($object);
                if ($payment) {
                    $row->payment_id = $payment->id;
                    $this->payments->cancel($payment, 'expired');
                }

                return;
            case 'checkout.session.async_payment_failed':
                $payment = $this->sessionPayment($object);
                if ($payment) {
                    $row->payment_id = $payment->id;
                    $this->payments->fail($payment, 'payment_failed');
                }

                return;
            case 'charge.refunded':
                $intent = (string) ($object['payment_intent'] ?? '');
                $payment = $intent !== '' ? Payment::query()->where('gateway', 'stripe')->where('gateway_capture_ref', $intent)->first() : null;
                if ($payment) {
                    $row->payment_id = $payment->id;
                    $this->applyRefund($payment, $object);
                }

                return;
            default:
                return;
        }
    }

    /**
     * `charge.refunded` fires for a partial refund too (a goodwill credit made in the dashboard).
     * Only a refund that covers the whole charge reverses the payment; a partial one is recorded
     * on the payment and flagged, so the person keeps what the money they still paid bought.
     */
    private function applyRefund(Payment $payment, array $charge): void
    {
        $charged = (int) ($charge['amount'] ?? 0);
        $refunded = (int) ($charge['amount_refunded'] ?? 0);
        // Stripe's amount_refunded is the running total for the charge; `refunded` is its "all of it"
        // flag. An event without either (an older payload) is treated as a full refund, as before.
        $full = ($charge['refunded'] ?? null) === true || $charged <= 0 || $refunded >= $charged;

        if (! $full) {
            $total = $this->payments->recordRefundAmount($payment, $refunded);
            Log::warning("Stripe refunded {$total} of {$charged} on payment #{$payment->id}: partial, nothing clawed back.");

            return;
        }

        try {
            $this->payments->reverse($payment, 'stripe_refund', viaGateway: false);
        } catch (PaymentException $e) {
            Log::warning("Stripe refund for payment #{$payment->id} ignored: ".$e->getMessage());
        }
    }

    /**
     * Close the Checkout Session at Stripe so a payment we cancelled here cannot be paid any more.
     * Best effort: a session Stripe already expired (or completed) answers with an error we ignore.
     */
    public function expireSession(Payment $payment): void
    {
        if ($payment->gateway !== 'stripe' || ! $payment->gateway_ref) {
            return;
        }

        try {
            $this->expireSessionAt($payment->gateway_ref);
        } catch (Throwable $e) {
            Log::info("Stripe session {$payment->gateway_ref} of payment #{$payment->id} was not expired: ".$e->getMessage());
        }
    }

    /** pay.show polling: ask Stripe about the session when the webhook has not arrived. */
    public function sync(Payment $payment): Payment
    {
        if ($payment->gateway !== 'stripe' || ! $payment->gateway_ref || $payment->status !== 'pending') {
            return $payment;
        }
        // pay.show is polled; without this every poll would be a live Stripe call, and a loop could
        // rate-limit the whole account (checkout creation and refunds included).
        if (! Cache::add('stripe:sync:'.$payment->getKey(), true, self::SYNC_EVERY)) {
            return $payment;
        }

        try {
            $session = $this->retrieveSession($payment->gateway_ref)->toArray();
        } catch (Throwable $e) {
            Log::info("Stripe sync for payment #{$payment->id} failed: ".$e->getMessage());

            return $payment;
        }

        if (($session['status'] ?? null) === 'expired') {
            return $this->payments->cancel($payment, 'expired');
        }
        if (($session['status'] ?? null) === 'complete' && ($session['client_reference_id'] ?? null) === $payment->uuid) {
            $this->applySession($payment, $session);
        }

        return $payment;
    }

    /** The checks every crediting path shares: paid, same amount, same currency. */
    private function applySession(Payment $payment, array $session): void
    {
        if (($session['payment_status'] ?? null) !== 'paid') {
            return; // async methods: wait for async_payment_succeeded
        }

        $amount = (int) ($session['amount_total'] ?? -1);
        $currency = strtolower((string) ($session['currency'] ?? ''));
        if ($amount !== $payment->amount_minor || $currency !== strtolower($payment->currency)) {
            Log::warning("Stripe amount mismatch on payment #{$payment->id}: reported {$amount} {$currency}, expected {$payment->amount_minor} {$payment->currency}");
            $this->payments->fail($payment, 'amount_mismatch', ['reported' => ['amount_minor' => $amount, 'currency' => strtoupper($currency)]]);

            return;
        }

        try {
            // The session was paid, so the money is real. A payment we cancelled here meanwhile
            // (superseded attempt, the person pressed Cancel, the abandoned sweep) is revived and
            // delivered rather than dropped — dropping it charged the card for nothing.
            $this->payments->settle($payment, [
                'gateway_capture_ref' => is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
                'meta' => ['stripe' => $this->trim($session)],
            ], revive: $payment->status === 'cancelled');
        } catch (PaymentException $e) {
            // Rejected / failed / already refunded: retrying the webhook would not help, so it counts
            // as processed — but the money moved, so the payment is flagged for an admin.
            Log::warning("Stripe session for payment #{$payment->id} not settled: ".$e->getMessage());
            $this->payments->flagForReview($payment, 'stripe_paid_not_settled', $e->getMessage());
        }
    }

    public function refund(Payment $payment, ?string $note): ?string
    {
        if (! $payment->gateway_capture_ref) {
            throw new PaymentException('no_capture', 'Stripe has no payment intent for this payment.', 422);
        }

        return $this->createRefund(['payment_intent' => $payment->gateway_capture_ref, 'metadata' => ['payment_id' => (string) $payment->id, 'note' => (string) $note]], $payment->uuid.':refund')->id;
    }

    /** Admin → Test connection. */
    public function check(): array
    {
        if (! filled(config('services.stripe.secret'))) {
            return ['ok' => false, 'message' => 'Enter the Stripe secret key and save first.'];
        }
        try {
            $balance = $this->retrieveBalance();
            $mode = $balance['livemode'] ?? false ? 'live' : 'test';
            $webhook = filled(config('services.stripe.webhook_secret')) ? '' : ' Add the webhook signing secret so payments are confirmed.';

            return ['ok' => true, 'message' => "Stripe answers ({$mode} mode).".$webhook];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Stripe refused the key: '.$e->getMessage()];
        }
    }

    /* ---- Stripe API calls, kept separate so tests can stub them --------------------------- */

    public function createSession(array $params, string $idempotencyKey): Session
    {
        return $this->client()->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    public function retrieveSession(string $id): Session
    {
        return $this->client()->checkout->sessions->retrieve($id);
    }

    public function expireSessionAt(string $id): Session
    {
        return $this->client()->checkout->sessions->expire($id);
    }

    public function createRefund(array $params, string $idempotencyKey): Refund
    {
        return $this->client()->refunds->create($params, ['idempotency_key' => $idempotencyKey]);
    }

    public function retrieveBalance(): array
    {
        return $this->client()->balance->retrieve()->toArray();
    }

    private function client(): StripeClient
    {
        $secret = (string) config('services.stripe.secret');
        if ($secret === '') {
            throw new PaymentException('gateway_unavailable', 'Stripe is not set up.', 503);
        }

        return new StripeClient(['api_key' => $secret, 'stripe_version' => '2024-06-20']);
    }

    /** The payment a Checkout Session belongs to: by session id AND our uuid (client_reference_id). */
    private function sessionPayment(array $session): ?Payment
    {
        $id = (string) ($session['id'] ?? '');
        $uuid = (string) ($session['client_reference_id'] ?? '');
        if ($id === '' || $uuid === '') {
            return null;
        }

        return Payment::query()->where('gateway', 'stripe')->where('gateway_ref', $id)->where('uuid', $uuid)->first();
    }

    private function paymentFor(string $type, array $object): ?Payment
    {
        if (str_starts_with($type, 'checkout.session.')) {
            return $this->sessionPayment($object);
        }
        if ($type === 'charge.refunded' && is_string($object['payment_intent'] ?? null)) {
            return Payment::query()->where('gateway', 'stripe')->where('gateway_capture_ref', $object['payment_intent'])->first();
        }

        return null;
    }

    /** What we keep of the provider's object: ids, amounts and states — never card details. */
    private function trim(array $object): array
    {
        return array_filter(array_intersect_key($object, array_flip([
            'id', 'object', 'amount_total', 'amount', 'amount_refunded', 'currency', 'payment_status', 'status', 'payment_intent', 'client_reference_id', 'livemode', 'created', 'expires_at',
        ])), fn ($v) => $v !== null && ! is_array($v) && ! is_object($v));
    }
}
