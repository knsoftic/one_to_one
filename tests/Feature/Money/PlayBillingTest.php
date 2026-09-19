<?php

namespace Tests\Feature\Money;

use App\Models\AppSetting;
use App\Models\CoinPack;
use App\Models\CoinTransaction;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\MoneyNotification;
use App\Services\CoinService;
use App\Services\Payments\PlayGateway;
use App\Services\PaymentService;
use App\Services\PlanService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Google Play Billing (Y2): the app sends a purchase token, the server checks it with Google,
 * credits once, and the daily sweep reverses what Google refunded.
 */
class PlayBillingTest extends TestCase
{
    use RefreshDatabase;

    private const APP_UA = 'Mozilla/5.0 (Linux; Android 14) One2OneApp/1.0';

    private const API = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/com.hunario.chat';

    private User $user;

    private CoinPack $pack;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::put(['paid_enabled' => true, 'play_enabled' => true]);
        config(['services.play.package_name' => 'com.hunario.chat', 'services.play.service_account' => $this->serviceAccountJson()]);
        $this->user = User::factory()->create();
        $this->pack = CoinPack::query()->create(['name' => 'Starter', 'coins' => 500, 'bonus_coins' => 25, 'price_minor' => 49900, 'currency' => 'PKR', 'play_product_id' => 'coins_500', 'is_active' => true]);
        $this->plan = Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'period' => 'month', 'price_minor' => 99900, 'currency' => 'PKR', 'play_product_id' => 'plan_pro_month', 'is_active' => true]);
    }

    /** A throwaway service account with a fresh RSA key, so the JWT is really signed. */
    private function serviceAccountJson(): string
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        foreach (['C:/xampp/apache/conf/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf', '/etc/ssl/openssl.cnf'] as $cnf) {
            if (is_file($cnf)) {
                $options['config'] = $cnf;
                break;
            }
        }
        $key = openssl_pkey_new($options);
        $this->assertNotFalse($key, 'openssl could not create a test RSA key');
        openssl_pkey_export($key, $pem, null, isset($options['config']) ? ['config' => $options['config']] : []);

        return json_encode([
            'type' => 'service_account', 'project_id' => 'test-project', 'client_email' => 'billing@test-project.iam.gserviceaccount.com',
            'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    private function purchase(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'androidpublisher#productPurchase', 'purchaseTimeMillis' => (string) (now()->getTimestampMs()), 'purchaseState' => 0, 'consumptionState' => 0,
            'productId' => 'coins_500', 'orderId' => 'GPA.1234-5678-9012-34567', 'acknowledgementState' => 0, 'obfuscatedExternalAccountId' => app(PlayGateway::class)->accountHash($this->user), 'regionCode' => 'PK',
        ], $overrides);
    }

    /** Google's token endpoint plus the purchase lookup / acknowledge for one token (earlier stubs are dropped). */
    private function fakeGoogle(array $purchase, int $status = 200): void
    {
        Http::swap(new HttpFactory($this->app->make(Dispatcher::class)));
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599, 'token_type' => 'Bearer']),
            self::API.'/purchases/products/*/tokens/*:acknowledge' => Http::response([], 200),
            self::API.'/purchases/products/*/tokens/*' => Http::response($status === 200 ? $purchase : ['error' => ['code' => $status, 'message' => 'boom']], $status),
        ]);
    }

    private function verify(array $overrides = [], ?User $as = null, bool $app = true)
    {
        $request = $this->actingAs($as ?? $this->user);
        if ($app) {
            $request = $request->withHeader('User-Agent', self::APP_UA);
        }

        return $request->postJson(route('pay.play.verify'), array_merge(['product_id' => 'coins_500', 'purchase_token' => 'tok-abc', 'order_id' => 'GPA.1234-5678-9012-34567'], $overrides));
    }

    public function test_a_valid_purchase_creates_the_payment_credits_the_coins_and_acknowledges(): void
    {
        $this->fakeGoogle($this->purchase());

        $response = $this->verify()->assertOk()
            ->assertJsonPath('status', 'fulfilled')
            ->assertJsonPath('payment.gateway', 'play')
            ->assertJsonPath('payment.status', 'fulfilled')
            ->assertJsonPath('payment.coins', 525)
            ->assertJsonPath('wallet.balance', 525);

        $payment = Payment::query()->find($response->json('payment.id'));
        $this->assertSame('android', $payment->platform);
        $this->assertSame(hash('sha256', 'tok-abc'), $payment->gateway_ref);
        $this->assertSame('GPA.1234-5678-9012-34567', $payment->gateway_capture_ref);
        $this->assertSame(49900, $payment->amount_minor);
        $this->assertSame(0, $payment->meta['google']['purchaseState']);
        $this->assertSame(525, Wallet::query()->find($this->user->id)->balance);
        $this->assertDatabaseHas(CoinTransaction::class, ['idempotency_key' => 'payment:'.$payment->id, 'amount' => 525]);

        // The token was exchanged with a signed JWT for the androidpublisher scope, then the purchase was read and acknowledged with it.
        Http::assertSent(function (ClientRequest $r) {
            if ($r->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }
            [, $claims] = explode('.', $r['assertion']);
            $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);

            return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && $claims['scope'] === 'https://www.googleapis.com/auth/androidpublisher' && $claims['iss'] === 'billing@test-project.iam.gserviceaccount.com';
        });
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'GET' && $r->url() === self::API.'/purchases/products/coins_500/tokens/tok-abc' && $r->header('Authorization')[0] === 'Bearer ya29.test');
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/tokens/tok-abc:acknowledge'));
    }

    public function test_a_replay_by_the_same_user_is_200_without_a_second_credit(): void
    {
        $this->fakeGoogle($this->purchase());
        $this->verify()->assertOk();
        $this->verify()->assertOk()->assertJsonPath('status', 'fulfilled');
        // Also when Google now reports it consumed (the app consumed after the first 200).
        $this->fakeGoogle($this->purchase(['consumptionState' => 1]));
        $this->verify()->assertOk()->assertJsonPath('status', 'fulfilled');

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, CoinTransaction::query()->count());
        $this->assertSame(525, Wallet::query()->find($this->user->id)->balance);
    }

    public function test_a_token_from_another_account_is_409(): void
    {
        $this->fakeGoogle($this->purchase());
        $this->verify()->assertOk();

        $other = User::factory()->create();
        $this->verify([], $other)->assertStatus(409)->assertJsonPath('code', 'token_other_account');
        $this->assertSame(1, Payment::query()->count());
        $this->assertNull(Wallet::query()->find($other->id));
    }

    public function test_google_says_cancelled_consumed_or_another_account(): void
    {
        $this->fakeGoogle($this->purchase(['purchaseState' => 1]));
        $this->verify()->assertStatus(422)->assertJsonPath('code', 'cancelled');

        $this->fakeGoogle($this->purchase(['consumptionState' => 1]));
        $this->verify(['purchase_token' => 'tok-used'])->assertStatus(422)->assertJsonPath('code', 'consumed');

        $this->fakeGoogle($this->purchase(['obfuscatedExternalAccountId' => hash('sha256', 'someone else')]));
        $this->verify(['purchase_token' => 'tok-other'])->assertStatus(422)->assertJsonPath('code', 'account_mismatch');

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, CoinTransaction::query()->count());
    }

    public function test_unknown_products_and_reused_order_ids_are_422(): void
    {
        $this->fakeGoogle($this->purchase());
        $this->verify(['product_id' => 'coins_999'])->assertStatus(422)->assertJsonPath('code', 'unknown_product');
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/purchases/'));

        $this->pack->update(['is_active' => false]);
        $this->verify()->assertStatus(422)->assertJsonPath('code', 'unknown_product');
        $this->pack->update(['is_active' => true]);

        $this->verify()->assertOk();
        $this->verify(['purchase_token' => 'tok-second-with-same-order'])->assertStatus(422)->assertJsonPath('code', 'order_reused');
        $this->assertSame(1, Payment::query()->count());
    }

    /** The client only picks the lookup URL: what was bought and which order it is come from Google. */
    public function test_the_product_and_order_of_a_token_are_taken_from_google_not_from_the_client(): void
    {
        // The token of the cheap pack, presented as the expensive plan: Google's productId wins.
        $this->fakeGoogle($this->purchase());
        $this->verify(['product_id' => 'plan_pro_month'])->assertStatus(422)->assertJsonPath('code', 'invalid_token');
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, CoinTransaction::query()->count());

        // A made-up order id is ignored; the payment carries the one Google reports.
        $response = $this->verify(['order_id' => 'GPA.0000-FAKE'])->assertOk();
        $payment = Payment::query()->find($response->json('payment.id'));
        $this->assertSame('GPA.1234-5678-9012-34567', $payment->gateway_capture_ref);
        $this->assertSame('coins_500', $payment->meta['google']['productId']);

        // So another buyer's order id cannot be pre-claimed to block their purchase either.
        $other = User::factory()->create();
        $this->fakeGoogle($this->purchase(['orderId' => 'GPA.9999-REAL', 'obfuscatedExternalAccountId' => app(PlayGateway::class)->accountHash($other)]));
        $this->verify(['purchase_token' => 'tok-other', 'order_id' => 'GPA.0000-FAKE'], $other)->assertOk();
        $this->assertSame('GPA.9999-REAL', Payment::query()->where('user_id', $other->id)->sole()->gateway_capture_ref);
    }

    public function test_google_being_down_is_503_and_nothing_is_written(): void
    {
        $this->fakeGoogle([], 500);
        $this->verify()->assertStatus(503)->assertJsonPath('code', 'google_unavailable');

        Cache::flush();
        Http::swap(new HttpFactory($this->app->make(Dispatcher::class)));
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400), self::API.'/*' => Http::response([], 200)]);
        $this->verify(['purchase_token' => 'tok-2'])->assertStatus(503)->assertJsonPath('code', 'google_unavailable');

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, CoinTransaction::query()->count());

        // An unknown token is the app's problem, not Google's: 422.
        $this->fakeGoogle([], 404);
        $this->verify(['purchase_token' => 'tok-3'])->assertStatus(422)->assertJsonPath('code', 'invalid_token');
    }

    public function test_a_plan_product_activates_the_plan_through_the_plan_service(): void
    {
        $sub = Subscription::query()->create(['user_id' => $this->user->id, 'plan_id' => $this->plan->id, 'status' => 'active', 'source' => 'play', 'benefits' => [], 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        $this->mock(PlanService::class, function ($mock) use ($sub) {
            $mock->shouldReceive('activate')->once()
                ->withArgs(fn (User $u, Plan $p, string $source, ?Payment $pay) => $u->id === $this->user->id && $p->id === $this->plan->id && $source === 'play' && $pay?->purpose === 'plan')
                ->andReturn($sub);
        });
        $this->fakeGoogle($this->purchase(['productId' => 'plan_pro_month', 'orderId' => 'GPA.plan-1']));

        $response = $this->verify(['product_id' => 'plan_pro_month', 'purchase_token' => 'tok-plan', 'order_id' => 'GPA.plan-1'])->assertOk()->assertJsonPath('status', 'fulfilled')->assertJsonPath('payment.purpose', 'plan');
        $payment = Payment::query()->find($response->json('payment.id'));
        $this->assertSame($sub->id, $payment->subscription_id);
        $this->assertSame(0, CoinTransaction::query()->count());
    }

    public function test_a_pending_purchase_is_202_and_fulfils_when_google_says_purchased(): void
    {
        $this->fakeGoogle($this->purchase(['purchaseState' => 2, 'orderId' => 'GPA.pend-1']));
        $response = $this->verify(['purchase_token' => 'tok-pend', 'order_id' => 'GPA.pend-1'])->assertStatus(202)->assertJsonPath('status', 'pending')->assertJsonPath('payment.status', 'pending')->assertJsonMissingPath('wallet');
        $payment = Payment::query()->find($response->json('payment.id'));
        $this->assertNull(Wallet::query()->find($this->user->id));
        Http::assertNotSent(fn (ClientRequest $r) => str_ends_with($r->url(), ':acknowledge'));

        // Still pending on the next check.
        $this->verify(['purchase_token' => 'tok-pend', 'order_id' => 'GPA.pend-1'])->assertStatus(202);
        $this->assertSame(1, Payment::query()->count());

        // The cash was paid: purchased now.
        $this->fakeGoogle($this->purchase(['purchaseState' => 0, 'orderId' => 'GPA.pend-1']));
        $this->verify(['purchase_token' => 'tok-pend', 'order_id' => 'GPA.pend-1'])->assertOk()->assertJsonPath('status', 'fulfilled')->assertJsonPath('payment.id', $payment->id);
        $this->assertSame('fulfilled', $payment->fresh()->status);
        $this->assertSame(525, Wallet::query()->find($this->user->id)->balance);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_web_browser_cannot_verify_play_purchases(): void
    {
        $this->fakeGoogle($this->purchase());
        $this->verify([], null, false)->assertStatus(422)->assertJsonPath('code', 'web_platform');
        Http::assertNothingSent();
        $this->assertSame(0, Payment::query()->count());

        // And the app cannot start a web-style payment with `play`.
        $this->actingAs($this->user)->withHeader('User-Agent', self::APP_UA)->postJson(route('pay.begin'), [
            'purpose' => 'coins', 'item_id' => $this->pack->id, 'gateway' => 'play', 'client_token' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonPath('code', 'use_play');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_products_list_and_the_admin_check_use_the_play_ids(): void
    {
        $this->assertSame([
            ['productId' => 'plan_pro_month', 'purpose' => 'plan', 'item_id' => $this->plan->id],
            ['productId' => 'coins_500', 'purpose' => 'coins', 'item_id' => $this->pack->id],
        ], app(PlayGateway::class)->products());
        $this->assertSame(hash('sha256', $this->user->id.config('app.key')), app(PlayGateway::class)->accountHash($this->user));

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            self::API.'/inappproducts*' => Http::response(['inappproduct' => [['sku' => 'coins_500', 'status' => 'active']]]),
        ]);
        $check = app(PlayGateway::class)->check();
        $this->assertTrue($check['ok']);
        $this->assertStringContainsString('1 in-app product(s)', $check['message']);
        $this->assertStringContainsString('Not found in the Play Console: plan_pro_month', $check['message']);
    }

    public function test_the_daily_sweep_reverses_voided_purchases_once_and_notifies(): void
    {
        Notification::fake();
        $this->fakeGoogle($this->purchase());
        $payment = Payment::query()->find($this->verify()->assertOk()->json('payment.id'));
        // Spend some so the clawback has a shortfall.
        app(CoinService::class)->debit($this->user, 100, 'badge', null, 'badge:x');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            self::API.'/purchases/voidedpurchases*' => Http::response(['voidedPurchases' => [
                ['purchaseToken' => 'tok-abc', 'orderId' => 'GPA.1234-5678-9012-34567', 'voidedTimeMillis' => (string) now()->getTimestampMs(), 'voidedReason' => 0],
                ['purchaseToken' => 'tok-unknown', 'orderId' => 'GPA.x', 'voidedTimeMillis' => (string) now()->getTimestampMs()],
            ]]),
        ]);

        $this->artisan('chat:play-sweep')->expectsOutputToContain('1 Google Play purchase(s) reversed')->assertSuccessful();

        $payment->refresh();
        $this->assertSame('refunded', $payment->status);
        $this->assertSame('play_void', $payment->meta['reason']);
        $this->assertSame(100, $payment->meta['shortfall']);
        $this->assertSame(0, Wallet::query()->find($this->user->id)->balance);
        $this->assertDatabaseHas(PaymentEvent::class, ['gateway' => 'play', 'event_id' => 'void:'.hash('sha256', 'tok-abc'), 'payment_id' => $payment->id]);
        $this->assertNotNull(PaymentEvent::query()->where('gateway', 'play')->first()->processed_at);
        $this->assertNotNull(Cache::get(PlayGateway::SWEEP_SINCE_KEY));
        Notification::assertSentTo($this->user, MoneyNotification::class, fn (MoneyNotification $n) => $n->type === 'payment_refunded' && str_contains($n->data['body'], 'Google refunded'));

        // Run again (Google keeps listing it for a while): nothing more happens.
        $this->artisan('chat:play-sweep')->expectsOutputToContain('0 Google Play purchase(s) reversed');
        $this->assertSame(1, CoinTransaction::query()->where('type', 'payment_refund')->count());

        // A replay of the voided token is refused.
        $this->fakeGoogle($this->purchase());
        $this->verify()->assertStatus(422)->assertJsonPath('code', 'voided');
    }

    /** A reversal that blew up must not be lost: the cursor may not move past an unapplied void. */
    public function test_a_void_the_sweep_could_not_apply_is_still_listed_for_the_next_run(): void
    {
        $this->fakeGoogle($this->purchase());
        $payment = Payment::query()->find($this->verify()->assertOk()->json('payment.id'));

        $voidedAt = now()->subDay();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            self::API.'/purchases/voidedpurchases*' => Http::response(['voidedPurchases' => [
                ['purchaseToken' => 'tok-abc', 'orderId' => 'GPA.1234-5678-9012-34567', 'voidedTimeMillis' => (string) $voidedAt->getTimestampMs()],
            ]]),
        ]);

        // The reversal fails (deadlock after the retries, DB timeout…).
        $this->partialMock(PaymentService::class, fn ($mock) => $mock->shouldReceive('reverse')->once()->andThrow(new RuntimeException('deadlock')));
        $this->assertSame(0, app(PlayGateway::class)->sweepVoided());
        $this->assertSame('fulfilled', $payment->fresh()->status);

        $cursor = Carbon::parse(Cache::get(PlayGateway::SWEEP_SINCE_KEY));
        $this->assertTrue($cursor->lessThanOrEqualTo($voidedAt), "the cursor moved to {$cursor}, past the void at {$voidedAt}");

        // Working again: the same void is applied, once.
        $this->app->forgetInstance(PaymentService::class);
        $this->assertSame(1, app(PlayGateway::class)->sweepVoided());
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(1, CoinTransaction::query()->where('type', 'payment_refund')->count());
    }
}
