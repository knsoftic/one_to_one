<?php

namespace Tests\Feature\Money;

use App\Models\AppSetting;
use App\Models\CoinPack;
use App\Models\CoinTransaction;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\StripeGateway;
use App\Services\PaymentService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use RuntimeException;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Stripe Checkout (Y2): the session is created server-side, and money is only credited from a
 * signed webhook or a server-side session retrieve — exactly once, whatever Stripe resends.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private User $user;

    private CoinPack $pack;

    /** @var StripeGateway&MockInterface */
    private $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::put(['paid_enabled' => true, 'stripe_enabled' => true, 'manual_enabled' => false]);
        config(['services.stripe.secret' => 'sk_test_123', 'services.stripe.webhook_secret' => self::SECRET]);
        $this->user = User::factory()->create();
        $this->pack = CoinPack::query()->create(['name' => 'Starter', 'coins' => 500, 'bonus_coins' => 0, 'price_minor' => 49900, 'currency' => 'PKR', 'is_active' => true]);

        // The real gateway with only the Stripe API calls stubbed (the constructor still runs).
        $this->stripe = Mockery::mock(StripeGateway::class, [app(PaymentService::class)])->makePartial();
        $this->stripe->shouldReceive('createSession')->andReturnUsing(fn (array $params, string $key) => Session::constructFrom([
            'id' => 'cs_test_'.substr(md5($key), 0, 8), 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test', 'status' => 'open',
        ]))->byDefault();
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    private function begin(): Payment
    {
        $response = $this->actingAs($this->user)->postJson(route('pay.begin'), [
            'purpose' => 'coins', 'item_id' => $this->pack->id, 'gateway' => 'stripe', 'client_token' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('payment.status', 'pending')->assertJsonPath('payment.gateway', 'stripe')->assertJsonPath('redirect', 'https://checkout.stripe.com/c/pay/cs_test');

        return Payment::query()->findOrFail($response->json('payment.id'));
    }

    private function sessionObject(Payment $payment, array $overrides = []): array
    {
        return array_merge([
            'id' => $payment->gateway_ref, 'object' => 'checkout.session', 'client_reference_id' => $payment->uuid,
            'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 49900, 'currency' => 'pkr', 'payment_intent' => 'pi_123', 'livemode' => false,
        ], $overrides);
    }

    private function event(string $id, string $type, array $object): string
    {
        return json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'created' => time(), 'livemode' => false, 'data' => ['object' => $object]]);
    }

    private function signature(string $payload, string $secret = self::SECRET, ?int $time = null): string
    {
        $time ??= time();

        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret);
    }

    private function deliver(string $payload, ?string $signature = null)
    {
        return $this->call('POST', route('webhooks.stripe'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature ?? $this->signature($payload), 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_begin_creates_the_checkout_session_with_our_ids_and_stores_the_session_id(): void
    {
        $this->stripe->shouldReceive('createSession')->once()->withArgs(function (array $params, string $key) {
            return $params['mode'] === 'payment'
                && $params['line_items'][0]['price_data']['unit_amount'] === 49900
                && $params['line_items'][0]['price_data']['currency'] === 'pkr'
                && $key === $params['client_reference_id']
                && str_contains($params['success_url'], '/return')
                && str_contains($params['cancel_url'], 'cancelled=1');
        })->andReturn(Session::constructFrom(['id' => 'cs_test_abc', 'url' => 'https://checkout.stripe.com/c/pay/cs_test']));

        $payment = $this->begin();
        $this->assertSame('cs_test_abc', $payment->gateway_ref);
        $this->assertSame($payment->uuid, Payment::query()->first()->uuid);
    }

    public function test_a_bad_signature_is_400_and_leaves_no_event_row(): void
    {
        $payment = $this->begin();
        $payload = $this->event('evt_1', 'checkout.session.completed', $this->sessionObject($payment));

        $this->deliver($payload, $this->signature($payload, 'whsec_wrong'))->assertStatus(400)->assertJsonPath('error', 'bad_signature');
        $this->deliver($payload, 'garbage')->assertStatus(400);
        $this->deliver($payload, $this->signature($payload, self::SECRET, time() - 3600))->assertStatus(400);

        $this->assertSame(0, PaymentEvent::query()->count());
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, CoinTransaction::query()->count());
    }

    public function test_a_completed_session_pays_and_fulfils_the_payment_once(): void
    {
        $payment = $this->begin();
        $payload = $this->event('evt_1', 'checkout.session.completed', $this->sessionObject($payment));

        $this->deliver($payload)->assertOk()->assertJsonPath('status', 'processed')->assertJsonPath('type', 'checkout.session.completed');

        $payment->refresh();
        $this->assertSame('fulfilled', $payment->status);
        $this->assertSame('pi_123', $payment->gateway_capture_ref);
        $this->assertSame(49900, $payment->meta['stripe']['amount_total']);
        $this->assertSame(500, Wallet::query()->find($this->user->id)->balance);
        $event = PaymentEvent::query()->where('gateway', 'stripe')->where('event_id', 'evt_1')->first();
        $this->assertNotNull($event->processed_at);
        $this->assertSame($payment->id, $event->payment_id);

        // Same event id again: 200 duplicate, nothing changes.
        $this->deliver($payload)->assertOk()->assertJsonPath('status', 'duplicate');
        // A different event id about the same session: processed, but idempotent on the payment.
        $this->deliver($this->event('evt_2', 'checkout.session.async_payment_succeeded', $this->sessionObject($payment)))->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame(1, CoinTransaction::query()->count());
        $this->assertSame(500, Wallet::query()->find($this->user->id)->balance);
        $this->assertSame(2, PaymentEvent::query()->count());
    }

    public function test_an_amount_mismatch_fails_the_payment_without_credit_and_is_flagged(): void
    {
        $payment = $this->begin();
        $this->deliver($this->event('evt_1', 'checkout.session.completed', $this->sessionObject($payment, ['amount_total' => 4990])))->assertOk()->assertJsonPath('status', 'processed');

        $payment->refresh();
        $this->assertSame('failed', $payment->status);
        $this->assertSame('amount_mismatch', $payment->meta['reason']);
        $this->assertSame(4990, $payment->meta['reported']['amount_minor']);
        $this->assertSame(0, CoinTransaction::query()->count());
        $this->assertNotNull(PaymentEvent::query()->first()->processed_at);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.money'))->assertOk()->assertSee('Amount mismatches');
        $this->actingAs($admin)->get(route('admin.payments.show', $payment))->assertOk()->assertSee('Amount mismatch');
    }

    public function test_a_processing_error_answers_500_and_the_retry_credits_once(): void
    {
        $payment = $this->begin();
        $payload = $this->event('evt_1', 'checkout.session.completed', $this->sessionObject($payment));

        $this->stripe->shouldReceive('apply')->once()->andThrow(new RuntimeException('database went away'));
        $this->stripe->shouldReceive('apply')->passthru();

        $this->deliver($payload)->assertStatus(500)->assertJsonPath('error', 'processing_failed');
        $event = PaymentEvent::query()->where('event_id', 'evt_1')->first();
        $this->assertNull($event->processed_at);
        $this->assertSame('database went away', $event->error);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, CoinTransaction::query()->count());

        // Stripe retries the same event: the row exists but is not processed, so it is applied now.
        $this->deliver($payload)->assertOk()->assertJsonPath('status', 'processed');
        $this->assertNotNull($event->fresh()->processed_at);
        $this->assertNull($event->fresh()->error);
        $this->assertSame('fulfilled', $payment->fresh()->status);
        $this->assertSame(1, CoinTransaction::query()->count());
        $this->assertSame(1, PaymentEvent::query()->count());
    }

    public function test_an_expired_session_cancels_and_a_failed_async_payment_fails(): void
    {
        $payment = $this->begin();
        $this->deliver($this->event('evt_1', 'checkout.session.expired', $this->sessionObject($payment, ['status' => 'expired', 'payment_status' => 'unpaid'])))->assertOk();
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('expired', $payment->fresh()->meta['reason']);

        $other = $this->begin();
        $this->deliver($this->event('evt_2', 'checkout.session.async_payment_failed', $this->sessionObject($other, ['payment_status' => 'unpaid'])))->assertOk();
        $this->assertSame('failed', $other->fresh()->status);

        // A session we do not know is ignored but counts as processed.
        $this->deliver($this->event('evt_3', 'checkout.session.completed', $this->sessionObject($other, ['id' => 'cs_unknown'])))->assertOk()->assertJsonPath('status', 'processed');
        $this->deliver($this->event('evt_4', 'payment_intent.created', ['id' => 'pi_x', 'object' => 'payment_intent']))->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame(0, CoinTransaction::query()->count());
    }

    public function test_a_refund_from_stripe_reverses_the_payment_and_claws_the_coins_back(): void
    {
        $payment = $this->begin();
        $this->deliver($this->event('evt_1', 'checkout.session.completed', $this->sessionObject($payment)))->assertOk();
        $this->assertSame(500, Wallet::query()->find($this->user->id)->balance);

        $this->deliver($this->event('evt_2', 'charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_123', 'amount' => 49900, 'amount_refunded' => 49900, 'currency' => 'pkr']))->assertOk()->assertJsonPath('status', 'processed');

        $payment->refresh();
        $this->assertSame('refunded', $payment->status);
        $this->assertSame('stripe_refund', $payment->meta['reason']);
        $this->assertSame(0, Wallet::query()->find($this->user->id)->balance);
        $this->assertDatabaseHas(CoinTransaction::class, ['idempotency_key' => 'payment:'.$payment->id.':refund', 'amount' => -500]);

        // Delivered twice by Stripe: one clawback.
        $this->deliver($this->event('evt_3', 'charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_123']))->assertOk();
        $this->assertSame(1, CoinTransaction::query()->where('type', 'payment_refund')->count());
    }

    public function test_pay_show_syncs_with_stripe_when_the_webhook_is_late(): void
    {
        $payment = $this->begin();
        $this->stripe->shouldReceive('retrieveSession')->with($payment->gateway_ref)->andReturn(Session::constructFrom($this->sessionObject($payment)));

        // Too early: no call to Stripe yet.
        $this->actingAs($this->user)->getJson(route('pay.show', $payment))->assertOk()->assertJsonPath('payment.status', 'pending')->assertJsonPath('payment.redirect', 'https://checkout.stripe.com/c/pay/cs_test');

        $this->travel(21)->seconds();
        $this->actingAs($this->user)->getJson(route('pay.show', $payment))->assertOk()->assertJsonPath('payment.status', 'fulfilled')->assertJsonPath('payment.redirect', null);
        $this->assertSame(500, Wallet::query()->find($this->user->id)->balance);
        $this->assertSame('pi_123', $payment->fresh()->gateway_capture_ref);

        // The webhook arriving afterwards changes nothing.
        $this->deliver($this->event('evt_1', 'checkout.session.completed', $this->sessionObject($payment)))->assertOk();
        $this->assertSame(1, CoinTransaction::query()->count());
    }

    public function test_the_return_page_never_credits_and_the_cancel_link_cancels(): void
    {
        $payment = $this->begin();

        $this->actingAs($this->user)->get(route('pay.return', ['payment' => $payment, 'gateway' => 'stripe']))
            ->assertRedirect(route('profile.edit', ['tab' => 'wallet', 'payment' => $payment->id, 'pay' => 'confirming']));
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, CoinTransaction::query()->count());

        $this->actingAs(User::factory()->create())->get(route('pay.return', ['payment' => $payment, 'gateway' => 'stripe']))->assertForbidden();

        $this->actingAs($this->user)->get(route('pay.return', ['payment' => $payment, 'gateway' => 'stripe', 'cancelled' => 1]))
            ->assertRedirect(route('profile.edit', ['tab' => 'wallet', 'payment' => $payment->id, 'pay' => 'cancelled']))->assertSessionHas('error');
        $this->assertSame('cancelled', $payment->fresh()->status);
    }

    public function test_the_webhook_routes_are_exempt_from_csrf(): void
    {
        $except = (new ReflectionClass(VerifyCsrfToken::class))->getStaticPropertyValue('neverVerify');
        $this->assertContains('webhooks/*', $except);
    }
}
