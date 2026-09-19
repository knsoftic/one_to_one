<?php

namespace Tests\Feature\Money;

use App\Models\AppSetting;
use App\Models\CoinPack;
use App\Models\CoinTransaction;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PayPal Orders v2 (Y2): the order is created here, approved on PayPal, and captured on the
 * server when the person comes back — once, with the amount checked.
 */
class PayPalCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    private CoinPack $pack;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::put(['paid_enabled' => true, 'paypal_enabled' => true, 'manual_enabled' => false]);
        config(['services.paypal.client_id' => 'client-id', 'services.paypal.secret' => 'client-secret', 'services.paypal.mode' => 'sandbox']);
        $this->user = User::factory()->create();
        $this->pack = CoinPack::query()->create(['name' => 'Starter', 'coins' => 500, 'bonus_coins' => 0, 'price_minor' => 49900, 'currency' => 'PKR', 'price_usd_minor' => 199, 'is_active' => true]);
    }

    /** The order as PayPal returns it after a capture. */
    private function capturedOrder(string $orderId, string $value = '1.99', string $status = 'COMPLETED', string $captureId = 'CAP-1'): array
    {
        return ['id' => $orderId, 'status' => $status, 'purchase_units' => [['payments' => ['captures' => [[
            'id' => $captureId, 'status' => $status === 'COMPLETED' ? 'COMPLETED' : 'PENDING', 'amount' => ['value' => $value, 'currency_code' => 'USD'], 'final_capture' => true,
        ]]]]]];
    }

    private function fake(array $extra = []): void
    {
        Http::fake(array_merge([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'A21.token', 'expires_in' => 32400]),
            self::API.'/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
                ['rel' => 'self', 'href' => self::API.'/v2/checkout/orders/ORDER-1'], ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1'],
            ]], 201),
        ], $extra));
    }

    private function begin(): Payment
    {
        $response = $this->actingAs($this->user)->postJson(route('pay.begin'), [
            'purpose' => 'coins', 'item_id' => $this->pack->id, 'gateway' => 'paypal', 'client_token' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('payment.status', 'pending')->assertJsonPath('payment.currency', 'USD')->assertJsonPath('payment.amount_minor', 199)
            ->assertJsonPath('redirect', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');

        return Payment::query()->findOrFail($response->json('payment.id'));
    }

    private function return(Payment $payment, ?User $as = null, string $token = 'ORDER-1')
    {
        return $this->actingAs($as ?? $this->user)->get(route('pay.return', ['payment' => $payment, 'gateway' => 'paypal', 'token' => $token]));
    }

    public function test_begin_creates_the_order_in_dollars_with_our_request_id(): void
    {
        $this->fake();
        $payment = $this->begin();

        $this->assertSame('ORDER-1', $payment->gateway_ref);
        Http::assertSent(fn (ClientRequest $r) => $r->url() === self::API.'/v2/checkout/orders'
            && $r->header('PayPal-Request-Id')[0] === $payment->uuid
            && $r['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '1.99']
            && $r['purchase_units'][0]['custom_id'] === $payment->uuid
            && str_contains($r['payment_source']['paypal']['experience_context']['return_url'], 'gateway=paypal'));
    }

    public function test_a_completed_capture_fulfils_the_payment_and_redirects_to_the_wallet(): void
    {
        $this->fake([self::API.'/v2/checkout/orders/ORDER-1/capture' => Http::response($this->capturedOrder('ORDER-1'), 201)]);
        $payment = $this->begin();

        $this->return($payment)->assertRedirect(route('profile.edit', ['tab' => 'wallet', 'payment' => $payment->id, 'pay' => 'ok']))->assertSessionHas('status');

        $payment->refresh();
        $this->assertSame('fulfilled', $payment->status);
        $this->assertSame('CAP-1', $payment->gateway_capture_ref);
        $this->assertSame('1.99', $payment->meta['paypal']['amount']['value']);
        $this->assertSame(500, Wallet::query()->find($this->user->id)->balance);
        $this->assertDatabaseHas(PaymentEvent::class, ['gateway' => 'paypal', 'event_id' => 'capture:CAP-1', 'payment_id' => $payment->id]);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/ORDER-1/capture') && $r->header('PayPal-Request-Id')[0] === $payment->uuid);

        // Refreshing the return page: PayPal says already captured → we fetch the order → still one credit.
        Http::fake([
            self::API.'/v2/checkout/orders/ORDER-1/capture' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422),
            self::API.'/v2/checkout/orders/ORDER-1' => Http::response($this->capturedOrder('ORDER-1')),
        ]);
        $this->return($payment)->assertRedirect(route('profile.edit', ['tab' => 'wallet', 'payment' => $payment->id, 'pay' => 'ok']));
        $this->assertSame(1, CoinTransaction::query()->count());
    }

    public function test_order_already_captured_is_resolved_by_fetching_the_order(): void
    {
        // Our server died after PayPal captured last time: the retry gets 422 and reads the order instead.
        $this->fake([
            self::API.'/v2/checkout/orders/ORDER-1/capture' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422),
            self::API.'/v2/checkout/orders/ORDER-1' => Http::response($this->capturedOrder('ORDER-1')),
        ]);
        $payment = $this->begin();

        $this->return($payment)->assertRedirect(route('profile.edit', ['tab' => 'wallet', 'payment' => $payment->id, 'pay' => 'ok']));
        $this->assertSame('fulfilled', $payment->fresh()->status);
        $this->assertSame(500, Wallet::query()->find($this->user->id)->balance);
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/v2/checkout/orders/ORDER-1'));
    }

    public function test_a_wrong_amount_fails_the_payment_without_credit(): void
    {
        $this->fake([self::API.'/v2/checkout/orders/ORDER-1/capture' => Http::response($this->capturedOrder('ORDER-1', '0.99'), 201)]);
        $payment = $this->begin();

        $this->return($payment)->assertRedirect(route('profile.edit', ['tab' => 'wallet', 'payment' => $payment->id, 'pay' => 'error']))->assertSessionHas('error');
        $payment->refresh();
        $this->assertSame('failed', $payment->status);
        $this->assertSame('amount_mismatch', $payment->meta['reason']);
        $this->assertSame('0.99', $payment->meta['reported']['amount']);
        $this->assertSame(0, CoinTransaction::query()->count());
        $this->assertSame(0, PaymentEvent::query()->count());
    }

    public function test_declined_and_not_approved_outcomes_leave_the_payment_pending(): void
    {
        $this->fake([self::API.'/v2/checkout/orders/ORDER-1/capture' => Http::sequence()
            ->push(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]], 422)
            ->push(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_NOT_APPROVED']]], 422)
            ->push([], 503),
        ]);
        $payment = $this->begin();

        // Declined: back to PayPal to pick another funding source.
        $this->return($payment)->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');
        $this->assertSame('pending', $payment->fresh()->status);
        // Not approved yet: stays pending with a message.
        $this->return($payment)->assertRedirect(route('profile.edit', ['tab' => 'wallet', 'payment' => $payment->id, 'pay' => 'confirming']));
        $this->assertSame('pending', $payment->fresh()->status);
        // PayPal down: nothing changes, the person is told to try again.
        $this->return($payment)->assertRedirect(route('profile.edit', ['tab' => 'wallet', 'payment' => $payment->id, 'pay' => 'error']))->assertSessionHas('error');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, CoinTransaction::query()->count());
    }

    public function test_the_return_is_owner_only_and_the_order_must_match(): void
    {
        $this->fake([self::API.'/v2/checkout/orders/ORDER-1/capture' => Http::response($this->capturedOrder('ORDER-1'), 201)]);
        $payment = $this->begin();

        $this->return($payment, User::factory()->create())->assertForbidden();
        $this->return($payment, null, 'ORDER-OTHER')->assertRedirect(route('profile.edit', ['tab' => 'wallet', 'payment' => $payment->id, 'pay' => 'error']))->assertSessionHas('error');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, CoinTransaction::query()->count());

        $this->actingAs($this->user)->get(route('pay.return', ['payment' => $payment, 'gateway' => 'paypal', 'cancelled' => 1]))->assertRedirect();
        $this->assertSame('cancelled', $payment->fresh()->status);
    }

    public function test_an_item_without_a_dollar_price_hides_paypal_and_refuses_begin(): void
    {
        $this->fake();
        $this->pack->update(['price_usd_minor' => null]);

        $this->assertNotContains('paypal', app(PaymentService::class)->methodsFor($this->user, Request::create('/'), $this->pack));
        $this->assertContains('paypal', app(PaymentService::class)->methodsFor($this->user, Request::create('/')));

        $this->actingAs($this->user)->postJson(route('pay.begin'), [
            'purpose' => 'coins', 'item_id' => $this->pack->id, 'gateway' => 'paypal', 'client_token' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonPath('code', 'gateway_unavailable');
        $this->assertSame(0, Payment::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_provider_error_at_begin_fails_the_payment_and_answers_503(): void
    {
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'A21.token', 'expires_in' => 32400]),
            self::API.'/v2/checkout/orders' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500),
        ]);

        $this->actingAs($this->user)->postJson(route('pay.begin'), [
            'purpose' => 'coins', 'item_id' => $this->pack->id, 'gateway' => 'paypal', 'client_token' => (string) Str::uuid(),
        ])->assertStatus(503)->assertJsonPath('code', 'gateway_error');
        $this->assertSame('failed', Payment::query()->first()->status);
    }

    public function test_the_verified_webhook_is_a_second_idempotent_path_and_handles_refunds(): void
    {
        config(['services.paypal.webhook_id' => 'WH-1']);
        $this->fake([
            self::API.'/v2/checkout/orders/ORDER-1/capture' => Http::response($this->capturedOrder('ORDER-1'), 201),
            self::API.'/v1/notifications/verify-webhook-signature' => Http::sequence()->push(['verification_status' => 'SUCCESS'])->push(['verification_status' => 'SUCCESS'])->push(['verification_status' => 'FAILURE']),
        ]);
        $payment = $this->begin();
        $this->return($payment);
        $this->assertSame(500, Wallet::query()->find($this->user->id)->balance);

        $headers = ['HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/cert', 'HTTP_PAYPAL_TRANSMISSION_ID' => 't1', 'HTTP_PAYPAL_TRANSMISSION_SIG' => 's', 'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String()];
        $completed = ['id' => 'WH-EVT-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAP-1', 'status' => 'COMPLETED', 'custom_id' => $payment->uuid, 'amount' => ['value' => '1.99', 'currency_code' => 'USD']]];
        $this->call('POST', route('webhooks.paypal'), [], [], [], $headers + ['CONTENT_TYPE' => 'application/json'], json_encode($completed))->assertOk();
        $this->assertSame(1, CoinTransaction::query()->count());

        $refunded = ['id' => 'WH-EVT-2', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => ['id' => 'REF-1', 'status' => 'COMPLETED', 'custom_id' => $payment->uuid, 'amount' => ['value' => '1.99', 'currency_code' => 'USD'], 'links' => [['rel' => 'up', 'href' => self::API.'/v2/payments/captures/CAP-1']]]];
        $this->call('POST', route('webhooks.paypal'), [], [], [], $headers + ['CONTENT_TYPE' => 'application/json'], json_encode($refunded))->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(0, Wallet::query()->find($this->user->id)->balance);
        $this->assertDatabaseHas(CoinTransaction::class, ['idempotency_key' => 'payment:'.$payment->id.':refund']);

        // Unverifiable delivery (PayPal answers FAILURE): 400, nothing recorded.
        $this->call('POST', route('webhooks.paypal'), [], [], [], $headers + ['CONTENT_TYPE' => 'application/json'], json_encode(['id' => 'WH-EVT-3', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => []]))->assertStatus(400);
        $this->assertDatabaseMissing(PaymentEvent::class, ['event_id' => 'WH-EVT-3']);
    }
}
