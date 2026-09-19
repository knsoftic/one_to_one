<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentException;
use App\Models\CoinPack;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\CoinService;
use App\Services\MonetisationService;
use App\Services\Payments\PayPalGateway;
use App\Services\Payments\PlayGateway;
use App\Services\Payments\StripeGateway;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Paying for a plan or a coin pack (Y2): start a payment, poll it, upload a transfer screenshot,
 * come back from Stripe/PayPal, cancel, and verify a Google Play purchase from the app.
 *
 * JSON shapes:
 * - begin  → {payment: {...}, redirect?: url, instructions?: {methods: {jazzcash: {label, text}, …}, note, amount, currency, amount_display}}
 * - show   → {payment: {id, uuid, status, gateway, purpose, plan_id, coin_pack_id, item, amount_minor, currency,
 *              amount_display, coins, manual_method, proof_ref, has_proof, review_note, reason, redirect,
 *              created_at, paid_at, fulfilled_at}, instructions?: (manual only)}
 * - errors → {code, message} with the PaymentException's status (404 / 409 / 422 / 503).
 */
class PaymentController extends Controller
{
    /** Seconds a Stripe payment may stay pending before pay.show asks Stripe directly. */
    public const STRIPE_SYNC_AFTER = 20;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly MonetisationService $money,
    ) {}

    /** POST pay.begin {purpose, item_id, gateway, client_token} */
    public function begin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in(Payment::PURPOSES)],
            'item_id' => ['required', 'integer', 'min:1'],
            'gateway' => ['required', Rule::in(array_keys(Payment::GATEWAYS))],
            'client_token' => ['required', 'uuid'],
        ]);

        $item = $data['purpose'] === 'plan' ? Plan::query()->find($data['item_id']) : CoinPack::query()->find($data['item_id']);
        if (! $item) {
            return $this->error(new PaymentException('invalid_item', 'That item cannot be bought.', 422));
        }

        try {
            $out = $this->payments->begin($request->user(), $data['purpose'], $item, $data['gateway'], $data['client_token'], $request);
        } catch (PaymentException $e) {
            return $this->error($e);
        }

        return response()->json(array_filter([
            'payment' => $this->payments->payload($out['payment']),
            'redirect' => $out['redirect'],
            'instructions' => $out['instructions'],
        ], fn ($v) => $v !== null));
    }

    /** GET pay.show — status for polling; asks Stripe itself when its webhook is late. */
    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizeOwner($request, $payment);

        // Google Play policy: the app is never handed a web payment method — no bank details, no
        // screenshot form, no checkout link. The mirror of verifyPlay()'s web guard.
        if ($payment->gateway !== 'play' && $this->money->platform($request) === 'android') {
            return $this->error(new PaymentException('app_platform', 'This payment was started on the website; please finish it there.', 422));
        }

        if ($payment->gateway === 'stripe' && $payment->status === 'pending' && $payment->created_at?->lte(now()->subSeconds(self::STRIPE_SYNC_AFTER))) {
            app(StripeGateway::class)->sync($payment);
        }

        return response()->json(array_filter([
            'payment' => $this->payments->payload($payment),
            'instructions' => $payment->gateway === 'manual' && in_array($payment->status, ['pending', 'review'], true) ? $this->payments->manualInstructions($payment) : null,
        ], fn ($v) => $v !== null), 200, ['Cache-Control' => 'no-store']);
    }

    /** POST pay.proof multipart {method, ref, note, screenshot} — manual pending → review. */
    public function proof(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizeOwner($request, $payment);

        if ($this->money->platform($request) === 'android') {
            return $this->error(new PaymentException('app_platform', 'Upload the screenshot on the website.', 422));
        }

        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(Payment::MANUAL_METHODS))],
            'ref' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:160'],
            'screenshot' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        try {
            $this->payments->submitProof($payment, $data['method'], $data['ref'], $data['note'] ?? null, $request->file('screenshot'));
        } catch (PaymentException $e) {
            return $this->error($e);
        }

        return response()->json(['payment' => $this->payments->payload($payment)]);
    }

    /**
     * GET pay.return — the browser comes back from Stripe or PayPal. Stripe is confirmed by its
     * webhook (or pay.show polling), never from here; PayPal is captured server-side here. Then
     * back to the Wallet / Premium tab with a message.
     */
    public function return(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorizeOwner($request, $payment);

        $tab = $payment->purpose === 'plan' ? 'premium' : 'wallet';
        $to = fn (string $state, string $message, string $key = 'status') => redirect()
            ->route('profile.edit', ['tab' => $tab, 'payment' => $payment->id, 'pay' => $state])
            ->with($key, $message);

        if ($request->boolean('cancelled')) {
            // Cancelling changes the payment, so a plain GET must not do it: only the signed
            // cancel_url the gateway driver built counts (PayPal appends `token` / `PayerID` on the
            // way back, which are not part of the signature). Without a valid signature this is
            // someone else's link — any page a signed-in person opens could otherwise cancel a
            // checkout that is in flight — so the payment is only shown, never touched.
            if ($request->hasValidSignatureWhileIgnoring(['token', 'PayerID'])) {
                $this->payments->cancel($payment, 'user_cancelled');
            }

            return $payment->status === 'fulfilled'
                ? $to('ok', 'Thanks — your payment went through.')
                : $to('cancelled', $payment->isFinal() ? 'Payment cancelled. Nothing was charged.' : 'Nothing was charged. You can finish this payment or cancel it from your wallet.', 'error');
        }

        if ($payment->gateway === 'paypal') {
            $orderId = (string) $request->query('token', '');
            try {
                $result = app(PayPalGateway::class)->capture($payment, $orderId);
            } catch (PaymentException $e) {
                return $to('error', $e->getMessage(), 'error');
            }

            return match ($result['status']) {
                'fulfilled', 'done' => $to('ok', $result['message']),
                'paid' => $to('confirming', 'Thanks — your payment went through. Delivery is being finished.'),
                'pending' => $to('confirming', $result['message']),
                'declined' => ! empty($result['redirect']) ? redirect()->away($result['redirect']) : $to('error', $result['message'], 'error'),
                default => $to('error', $result['message'], 'error'),
            };
        }

        if ($payment->status === 'fulfilled') {
            return $to('ok', 'Thanks — your payment went through.');
        }
        if ($payment->isFinal()) {
            return $to('error', 'This payment did not go through.', 'error');
        }

        return $to('confirming', 'Confirming your payment…');
    }

    /** DELETE pay.cancel — pending only. */
    public function cancel(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizeOwner($request, $payment);

        if ($payment->status !== 'pending') {
            return $this->error(new PaymentException('not_pending', "This payment is {$payment->status} and cannot be cancelled.", 409));
        }
        $this->payments->cancel($payment, 'user_cancelled');

        return response()->json(['payment' => $this->payments->payload($payment)]);
    }

    /**
     * POST pay.play.verify {product_id, purchase_token, order_id} from the Android app (also the
     * restore path). 200 fulfilled (the app may consume), 202 pending (Google is still waiting
     * for the money), 4xx/503 as documented on PlayGateway::verify().
     */
    public function verifyPlay(Request $request): JsonResponse
    {
        if ($this->money->platform($request) !== 'android') {
            return $this->error(new PaymentException('web_platform', 'Google Play purchases are verified from the app only.', 422));
        }

        $data = $request->validate([
            'product_id' => ['required', 'string', 'max:80'],
            'purchase_token' => ['required', 'string', 'max:2000'],
            'order_id' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $result = app(PlayGateway::class)->verify($request->user(), $data['product_id'], $data['purchase_token'], $data['order_id'] ?? null, $request);
        } catch (PaymentException $e) {
            return $this->error($e);
        }

        $payload = ['status' => $result['status'], 'payment' => $this->payments->payload($result['payment'])];
        if ($result['status'] === 'fulfilled') {
            $payload['wallet'] = app(CoinService::class)->summary($request->user());
        }

        return response()->json($payload, $result['status'] === 'fulfilled' ? 200 : 202);
    }

    private function authorizeOwner(Request $request, Payment $payment): void
    {
        abort_unless($payment->user_id !== null && $payment->user_id === $request->user()->getKey(), 403);
    }

    private function error(PaymentException $e): JsonResponse
    {
        return response()->json($e->toArray(), $e->status);
    }
}
