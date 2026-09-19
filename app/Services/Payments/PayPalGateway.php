<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentException;
use App\Models\AppSetting;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\PaymentService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PayPal Orders v2 through the redirect approval flow (Y2): no PayPal JS. The order is created
 * here, the person approves it on PayPal, and the server captures it when they come back —
 * with `PayPal-Request-Id = payment uuid` so a repeated capture is the same capture.
 */
class PayPalGateway implements PaymentGateway
{
    public const TOKEN_TTL = 8 * 3600;

    public function __construct(private readonly PaymentService $payments) {}

    public function key(): string
    {
        return 'paypal';
    }

    public function available(): bool
    {
        return (bool) AppSetting::get('paypal_enabled') && filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret'));
    }

    public function baseUrl(): string
    {
        return config('services.paypal.mode') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    /** Create the order; the person is sent to PayPal to approve it. */
    public function start(Payment $payment): array
    {
        if ($payment->currency !== 'USD' || $payment->amount_minor <= 0) {
            throw new PaymentException('gateway_unavailable', 'PayPal needs a US-dollar price for this item.', 422);
        }

        $response = $this->api()->withHeaders(['PayPal-Request-Id' => $payment->uuid])->post($this->baseUrl().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $payment->id,
                'custom_id' => $payment->uuid,
                'description' => $payment->itemLabel().' · '.config('app.name'),
                'amount' => ['currency_code' => 'USD', 'value' => $this->money($payment->amount_minor)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => route('pay.return', ['payment' => $payment->id, 'gateway' => 'paypal']),
                'cancel_url' => route('pay.return', ['payment' => $payment->id, 'gateway' => 'paypal', 'cancelled' => 1]),
            ]]],
        ]);

        if (! $response->successful() || ! is_string($response->json('id'))) {
            Log::warning('PayPal order creation failed: HTTP '.$response->status().' '.$response->body());
            throw new PaymentException('gateway_error', 'PayPal did not accept the order. Try again in a moment.', 503);
        }

        $approve = $this->link($response->json('links', []), 'payer-action') ?? $this->link($response->json('links', []), 'approve');
        if (! $approve) {
            throw new PaymentException('gateway_error', 'PayPal did not return an approval link.', 503);
        }

        $payment->forceFill(['gateway_ref' => $response->json('id'), 'meta' => array_merge($payment->meta ?? [], ['approve_url' => $approve])])->save();

        return ['redirect' => $approve];
    }

    /**
     * The person came back from PayPal: capture the order on the server.
     *
     * @return array{status: 'fulfilled'|'paid'|'pending'|'declined'|'failed'|'done', redirect?: string, message: string}
     */
    public function capture(Payment $payment, string $orderId): array
    {
        if ($payment->gateway !== 'paypal' || $orderId === '' || $orderId !== $payment->gateway_ref) {
            throw new PaymentException('order_mismatch', 'This PayPal order does not belong to this payment.', 422);
        }
        if ($payment->isFinal() || $payment->status === 'paid') {
            return ['status' => 'done', 'message' => 'This payment was already handled.'];
        }

        $response = $this->api()->withHeaders(['PayPal-Request-Id' => $payment->uuid])->post($this->baseUrl()."/v2/checkout/orders/{$orderId}/capture", (object) []);
        $order = $response->json() ?? [];

        if (! $response->successful()) {
            $issue = (string) ($order['details'][0]['issue'] ?? $order['name'] ?? '');
            if ($issue === 'ORDER_ALREADY_CAPTURED') {
                $again = $this->api()->get($this->baseUrl()."/v2/checkout/orders/{$orderId}");
                if (! $again->successful()) {
                    throw new PaymentException('gateway_error', 'PayPal did not answer. Try again in a moment.', 503);
                }
                $order = $again->json() ?? [];
            } elseif ($issue === 'INSTRUMENT_DECLINED') {
                return ['status' => 'declined', 'redirect' => $payment->meta['approve_url'] ?? null, 'message' => 'PayPal declined that payment method. Choose another one.'];
            } elseif ($issue === 'ORDER_NOT_APPROVED') {
                return ['status' => 'pending', 'message' => 'The PayPal payment was not approved yet.'];
            } elseif ($response->serverError() || $response->status() === 0) {
                throw new PaymentException('gateway_error', 'PayPal did not answer. Try again in a moment.', 503);
            } else {
                Log::warning("PayPal capture of order {$orderId} failed: ".$response->body());
                $this->payments->fail($payment, 'capture_failed', ['paypal' => ['issue' => $issue, 'http' => $response->status()]]);

                return ['status' => 'failed', 'message' => 'PayPal could not complete the payment.'];
            }
        }

        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (($order['status'] ?? null) !== 'COMPLETED' || ! $capture || ($capture['status'] ?? null) !== 'COMPLETED') {
            $state = (string) ($capture['status'] ?? $order['status'] ?? 'unknown');
            if (in_array($state, ['PENDING', 'APPROVED', 'CREATED', 'PAYER_ACTION_REQUIRED'], true)) {
                return ['status' => 'pending', 'message' => 'PayPal is still processing the payment.'];
            }
            $this->payments->fail($payment, 'capture_failed', ['paypal' => ['status' => $state]]);

            return ['status' => 'failed', 'message' => 'PayPal could not complete the payment.'];
        }

        $value = (string) ($capture['amount']['value'] ?? '');
        $code = strtoupper((string) ($capture['amount']['currency_code'] ?? ''));
        if ($value !== $this->money($payment->amount_minor) || $code !== $payment->currency) {
            Log::warning("PayPal amount mismatch on payment #{$payment->id}: {$value} {$code}");
            $this->payments->fail($payment, 'amount_mismatch', ['reported' => ['amount' => $value, 'currency' => $code]]);

            return ['status' => 'failed', 'message' => 'The amount PayPal took does not match. Support will look into it.'];
        }

        $captureId = (string) $capture['id'];
        $event = $this->payments->recordEvent('paypal', 'capture:'.$captureId, 'capture', $this->trim($capture), $payment);
        $this->payments->processEvent($event, function (PaymentEvent $row) use ($payment, $captureId, $capture) {
            $row->payment_id = $payment->id;
            $this->payments->settle($payment, ['gateway_capture_ref' => $captureId, 'meta' => ['paypal' => $this->trim($capture)]]);
        });
        $payment->refresh();

        return ['status' => $payment->status === 'fulfilled' ? 'fulfilled' : 'paid', 'message' => 'Thanks — your PayPal payment went through.'];
    }

    /**
     * Optional webhook (needs `paypal_webhook_id`): a second, idempotent path for completed
     * captures and refunds. Returns false when the signature could not be verified.
     */
    public function handleWebhook(Request $request): bool
    {
        $webhookId = (string) config('services.paypal.webhook_id');
        if ($webhookId === '') {
            return false;
        }

        $body = $request->json()->all();
        $verify = $this->api()->post($this->baseUrl().'/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $webhookId,
            'webhook_event' => $body,
        ]);
        if (! $verify->successful() || $verify->json('verification_status') !== 'SUCCESS') {
            return false;
        }

        $eventId = (string) ($body['id'] ?? '');
        $type = (string) ($body['event_type'] ?? '');
        $resource = (array) ($body['resource'] ?? []);
        if ($eventId === '' || $type === '') {
            return false;
        }

        $payment = $this->resourcePayment($type, $resource);
        $event = $this->payments->recordEvent('paypal', $eventId, $type, $this->trim($resource), $payment);
        if ($this->payments->isProcessed($event)) {
            return true;
        }

        $this->payments->processEvent($event, function (PaymentEvent $row) use ($type, $resource, $payment) {
            if (! $payment) {
                return;
            }
            $row->payment_id = $payment->id;
            if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
                $value = (string) ($resource['amount']['value'] ?? '');
                $code = strtoupper((string) ($resource['amount']['currency_code'] ?? ''));
                if ($value !== $this->money($payment->amount_minor) || $code !== $payment->currency) {
                    $this->payments->fail($payment, 'amount_mismatch', ['reported' => ['amount' => $value, 'currency' => $code]]);

                    return;
                }
                if (in_array($payment->status, ['pending', 'review', 'paid'], true)) {
                    $this->payments->settle($payment, ['gateway_capture_ref' => (string) $resource['id'], 'meta' => ['paypal' => $this->trim($resource)]]);
                }
            } elseif ($type === 'PAYMENT.CAPTURE.REFUNDED') {
                try {
                    $this->payments->reverse($payment, 'paypal_refund', viaGateway: false);
                } catch (PaymentException $e) {
                    Log::warning("PayPal refund for payment #{$payment->id} ignored: ".$e->getMessage());
                }
            }
        });

        return true;
    }

    public function refund(Payment $payment, ?string $note): ?string
    {
        if (! $payment->gateway_capture_ref) {
            throw new PaymentException('no_capture', 'PayPal has no capture for this payment.', 422);
        }

        $response = $this->api()->withHeaders(['PayPal-Request-Id' => $payment->uuid.':refund'])
            ->post($this->baseUrl()."/v2/payments/captures/{$payment->gateway_capture_ref}/refund", array_filter(['note_to_payer' => $note ? mb_substr($note, 0, 255) : null]) ?: (object) []);

        if (! $response->successful() || ! is_string($response->json('id'))) {
            throw new PaymentException('gateway_error', 'PayPal refused the refund: '.(string) ($response->json('details.0.description') ?? $response->json('message') ?? 'HTTP '.$response->status()), 503);
        }

        return $response->json('id');
    }

    /** Admin → Test connection: fetch an access token. */
    public function check(): array
    {
        if (! filled(config('services.paypal.client_id')) || ! filled(config('services.paypal.secret'))) {
            return ['ok' => false, 'message' => 'Enter the PayPal client ID and secret and save first.'];
        }
        try {
            $this->token(fresh: true);

            return ['ok' => true, 'message' => 'PayPal accepted the credentials ('.(config('services.paypal.mode') === 'live' ? 'live' : 'sandbox').').'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'PayPal refused the credentials: '.$e->getMessage()];
        }
    }

    /** A client-credentials token, cached for 8 hours. */
    public function token(bool $fresh = false): string
    {
        $key = 'paypal:token:'.md5((string) config('services.paypal.client_id').config('services.paypal.mode'));
        if (! $fresh && is_string($cached = Cache::get($key)) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->timeout(15)
            ->withBasicAuth((string) config('services.paypal.client_id'), (string) config('services.paypal.secret'))
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new PaymentException('gateway_error', 'PayPal did not issue an access token (HTTP '.$response->status().').', 503);
        }

        Cache::put($key, $token, min(self::TOKEN_TTL, max(60, (int) $response->json('expires_in', self::TOKEN_TTL) - 300)));

        return $token;
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->asJson()->timeout(20);
    }

    /** "12.50" from 1250. */
    private function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    private function link(array $links, string $rel): ?string
    {
        foreach ($links as $link) {
            if (($link['rel'] ?? null) === $rel && is_string($link['href'] ?? null)) {
                return $link['href'];
            }
        }

        return null;
    }

    /** The payment a webhook resource is about: by our uuid (custom_id) or the capture id. */
    private function resourcePayment(string $type, array $resource): ?Payment
    {
        $uuid = (string) ($resource['custom_id'] ?? '');
        if ($uuid !== '' && ($p = Payment::query()->where('gateway', 'paypal')->where('uuid', $uuid)->first())) {
            return $p;
        }
        $captureId = $type === 'PAYMENT.CAPTURE.REFUNDED'
            ? (string) basename((string) ($this->link($resource['links'] ?? [], 'up') ?? ''))
            : (string) ($resource['id'] ?? '');

        return $captureId !== '' ? Payment::query()->where('gateway', 'paypal')->where('gateway_capture_ref', $captureId)->first() : null;
    }

    private function trim(array $resource): array
    {
        $keep = array_intersect_key($resource, array_flip(['id', 'status', 'custom_id', 'invoice_id', 'create_time', 'update_time', 'final_capture']));
        if (isset($resource['amount']) && is_array($resource['amount'])) {
            $keep['amount'] = array_intersect_key($resource['amount'], array_flip(['value', 'currency_code']));
        }

        return $keep;
    }

    /** For tests and the admin check: the last response is not kept anywhere else. */
    protected function lastResponse(): ?Response
    {
        return null;
    }
}
