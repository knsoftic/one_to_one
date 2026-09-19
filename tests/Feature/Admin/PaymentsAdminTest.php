<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\CoinPack;
use App\Models\CoinTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CoinService;
use App\Services\Payments\StripeGateway;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Stripe\Refund;
use Tests\TestCase;

/**
 * Admin → Money: the overview, the payments queue and its actions, coin packs, and the
 * per-gateway "Test connection" checks (Y2).
 */
class PaymentsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private CoinPack $pack;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        AppSetting::put(['paid_enabled' => true, 'manual_enabled' => true, 'manual_jazzcash' => 'JazzCash 0300-1234567']);
        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create(['name' => 'Ayesha Khan']);
        $this->pack = CoinPack::query()->create(['name' => 'Starter', 'coins' => 500, 'bonus_coins' => 0, 'price_minor' => 49900, 'currency' => 'PKR', 'is_active' => true]);
    }

    /** A manual payment in the given state, with a screenshot when it is past pending. */
    private function payment(string $status = 'review', array $overrides = []): Payment
    {
        $payment = Payment::query()->create(array_merge([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'purpose' => 'coins', 'coin_pack_id' => $this->pack->id, 'gateway' => 'manual',
            'status' => $status, 'platform' => 'web', 'amount_minor' => 49900, 'currency' => 'PKR', 'coins' => 500,
        ], $overrides));
        if ($status !== 'pending' && $payment->gateway === 'manual') {
            $path = UploadedFile::fake()->image('proof.png')->store('payments/'.$payment->id, 'local');
            $payment->forceFill(['manual_method' => 'jazzcash', 'proof_ref' => 'TX-'.$payment->id, 'proof_path' => $path])->save();
        }

        return $payment;
    }

    public function test_the_list_defaults_to_the_review_queue_and_filters_work(): void
    {
        $review = $this->payment('review');
        $done = $this->payment('fulfilled', ['gateway' => 'stripe', 'gateway_ref' => 'cs_1', 'fulfilled_at' => now()]);
        $other = User::factory()->create(['name' => 'Bilal Ahmed']);
        $this->payment('review', ['user_id' => $other->id, 'purpose' => 'plan', 'coin_pack_id' => null, 'coins' => null, 'amount_minor' => 99900]);

        $this->actingAs($this->admin)->get(route('admin.payments'))->assertOk()
            ->assertSee('Ayesha Khan')->assertSee('Bilal Ahmed')->assertSee('#'.$review->id)->assertDontSee('#'.$done->id.'</a>', false);

        $this->actingAs($this->admin)->get(route('admin.payments', ['status' => 'fulfilled']))->assertOk()->assertSee('#'.$done->id)->assertDontSee('Bilal Ahmed');
        $this->actingAs($this->admin)->get(route('admin.payments', ['status' => 'all', 'gateway' => 'stripe']))->assertOk()->assertSee('Card (Stripe)')->assertDontSee('Bilal Ahmed');
        $this->actingAs($this->admin)->get(route('admin.payments', ['status' => 'all', 'purpose' => 'plan']))->assertOk()->assertSee('Bilal Ahmed')->assertDontSee('Ayesha Khan');
        $this->actingAs($this->admin)->get(route('admin.payments', ['status' => 'all', 'q' => 'Bilal']))->assertOk()->assertSee('Bilal Ahmed')->assertDontSee('Ayesha Khan');
        $this->actingAs($this->admin)->get(route('admin.payments', ['status' => 'all', 'q' => 'cs_1']))->assertOk()->assertSee('#'.$done->id)->assertDontSee('Bilal Ahmed');
        $this->actingAs($this->admin)->get(route('admin.payments', ['status' => 'all', 'q' => (string) $review->id]))->assertOk()->assertSee('#'.$review->id);
        $this->actingAs($this->admin)->get(route('admin.payments', ['status' => 'all', 'date' => now()->subDays(3)->toDateString()]))->assertOk()->assertSee('No payments here');
        $this->actingAs($this->admin)->get(route('admin.payments', ['status' => 'nope']))->assertSessionHasErrors('status');
    }

    public function test_the_detail_page_shows_the_payment_the_screenshot_and_the_history(): void
    {
        $payment = $this->payment('review');
        $this->payment('fulfilled', ['fulfilled_at' => now()]);
        app(CoinService::class)->credit($this->user, 500, 'purchase', null, 'payment:x', 'Bought 500 coins');

        $this->actingAs($this->admin)->get(route('admin.payments.show', $payment))->assertOk()
            ->assertSee('Payment #'.$payment->id)->assertSee('Ayesha Khan')->assertSee('Rs 499')->assertSee('JazzCash')->assertSee('TX-'.$payment->id)
            ->assertSee(route('admin.payments.proof', $payment), false)
            ->assertSee('Their other payments')->assertSee('Bought 500 coins')
            ->assertSee(route('admin.payments.approve', $payment), false)->assertSee(route('admin.payments.reject', $payment), false)
            ->assertDontSee(route('admin.payments.refund', $payment), false);
    }

    public function test_approve_reject_refund_and_retry_are_audited_with_their_notes(): void
    {
        $a = $this->payment('review');
        $this->actingAs($this->admin)->post(route('admin.payments.approve', $a), ['note' => 'ok'])->assertRedirect(route('admin.payments.show', $a))->assertSessionHas('status');
        $this->assertSame('fulfilled', $a->fresh()->status);
        $this->assertSame(500, Wallet::query()->find($this->user->id)->balance);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'payment.approved', 'admin_id' => $this->admin->id, 'target_type' => 'Payment', 'target_id' => $a->id]);
        $this->assertSame('ok', AdminAuditLog::query()->where('action', 'payment.approved')->first()->meta['note']);

        $r = $this->payment('review');
        $this->actingAs($this->admin)->post(route('admin.payments.reject', $r), ['note' => 'Blurry screenshot'])->assertRedirect(route('admin.payments.show', $r));
        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertSame('Blurry screenshot', AdminAuditLog::query()->where('action', 'payment.rejected')->first()->meta['note']);

        $this->actingAs($this->admin)->post(route('admin.payments.refund', $a), ['note' => 'Sent back by bank'])->assertRedirect(route('admin.payments.show', $a))->assertSessionHas('status');
        $this->assertSame('refunded', $a->fresh()->status);
        $this->assertSame(0, Wallet::query()->find($this->user->id)->balance);
        $this->assertSame('Sent back by bank', AdminAuditLog::query()->where('action', 'payment.refunded')->first()->meta['note']);

        // Paid but not delivered → Retry delivers and is audited.
        $p = $this->payment('paid', ['paid_at' => now(), 'gateway_ref' => 'manual:x']);
        $this->actingAs($this->admin)->get(route('admin.payments.show', $p))->assertOk()->assertSee('Retry delivery')->assertSee('Money taken, nothing delivered');
        $this->actingAs($this->admin)->post(route('admin.payments.retry', $p))->assertRedirect(route('admin.payments.show', $p))->assertSessionHas('status', 'Delivered.');
        $this->assertSame('fulfilled', $p->fresh()->status);
        $this->assertSame(500, Wallet::query()->find($this->user->id)->balance);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'payment.retried', 'target_id' => $p->id]);

        // Wrong state → error flash, nothing changes, no audit row.
        $this->actingAs($this->admin)->post(route('admin.payments.retry', $p))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('admin.payments.approve', $r))->assertSessionHas('error');
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'payment.retried')->count());
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'payment.approved')->count());
    }

    public function test_a_stripe_refund_can_go_through_the_gateway(): void
    {
        config(['services.stripe.secret' => 'sk_test_x']);
        $stripe = Mockery::mock(StripeGateway::class, [app(PaymentService::class)])->makePartial();
        $stripe->shouldReceive('createRefund')->once()->withArgs(fn (array $params) => $params['payment_intent'] === 'pi_9')->andReturn(Refund::constructFrom(['id' => 're_1']));
        $this->app->instance(StripeGateway::class, $stripe);
        $payment = $this->payment('fulfilled', ['gateway' => 'stripe', 'gateway_ref' => 'cs_9', 'gateway_capture_ref' => 'pi_9', 'fulfilled_at' => now()]);
        app(CoinService::class)->credit($this->user, 500, 'purchase', $payment, 'payment:'.$payment->id);

        $this->actingAs($this->admin)->post(route('admin.payments.refund', $payment), ['note' => 'Customer asked', 'via_gateway' => 1])->assertSessionHas('status', fn ($m) => str_contains($m, 'through Card (Stripe)'));
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('re_1', $payment->fresh()->refund_ref);
        $this->assertSame(0, Wallet::query()->find($this->user->id)->balance);

        // When Stripe refuses, nothing changes.
        $second = $this->payment('fulfilled', ['gateway' => 'stripe', 'gateway_ref' => 'cs_10', 'gateway_capture_ref' => 'pi_10', 'fulfilled_at' => now()]);
        $stripe->shouldReceive('createRefund')->once()->andThrow(new RuntimeException('card_declined'));
        $this->actingAs($this->admin)->post(route('admin.payments.refund', $second), ['note' => 'x', 'via_gateway' => 1]);
        $this->assertSame('fulfilled', $second->fresh()->status);
    }

    public function test_the_proof_is_admin_only_streamed_privately_and_audited(): void
    {
        $payment = $this->payment('review');

        $this->actingAs($this->user)->get(route('admin.payments.proof', $payment))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.payments.proof', $payment))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'payment.proof_viewed', 'admin_id' => $this->admin->id, 'target_id' => $payment->id]);
        $this->assertStringContainsString('payment screenshot', AdminAuditLog::query()->where('action', 'payment.proof_viewed')->first()->description);

        $this->actingAs($this->admin)->get(route('admin.payments.proof', $this->payment('pending')))->assertNotFound();
    }

    public function test_coin_packs_can_be_added_edited_and_deleted_unless_referenced(): void
    {
        $this->actingAs($this->admin)->get(route('admin.coin-packs'))->assertOk()->assertSee('Starter')->assertSee('499.00');

        $this->actingAs($this->admin)->post(route('admin.coin-packs.store'), [
            'name' => 'Mega', 'coins' => 5000, 'bonus_coins' => 500, 'price' => '3999.00', 'currency' => 'PKR', 'price_usd' => '14.99', 'play_product_id' => 'coins_5000', 'is_active' => 1, 'sort' => 20,
        ])->assertRedirect(route('admin.coin-packs'))->assertSessionHas('status');
        $mega = CoinPack::query()->firstWhere('name', 'Mega');
        $this->assertSame(399900, $mega->price_minor);
        $this->assertSame(1499, $mega->price_usd_minor);
        $this->assertSame(5500, $mega->totalCoins());
        $this->assertTrue($mega->is_active);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'coin_pack.created', 'target_type' => 'CoinPack', 'target_id' => $mega->id]);

        // Validation: a Play id must be unique and well-formed; the price must be money.
        $this->actingAs($this->admin)->post(route('admin.coin-packs.store'), ['name' => 'Dup', 'coins' => 1, 'price' => '1', 'currency' => 'PKR', 'play_product_id' => 'coins_5000'])->assertSessionHasErrors('play_product_id');
        $this->actingAs($this->admin)->post(route('admin.coin-packs.store'), ['name' => 'Bad', 'coins' => 1, 'price' => 'free', 'currency' => 'PKR'])->assertSessionHasErrors('price');
        $this->actingAs($this->admin)->post(route('admin.coin-packs.store'), ['name' => 'Bad', 'coins' => 1, 'price' => '1', 'currency' => 'XXX'])->assertSessionHasErrors('currency');

        $this->actingAs($this->admin)->put(route('admin.coin-packs.update', $mega), [
            'name' => 'Mega+', 'coins' => 5000, 'bonus_coins' => 750, 'price' => '3499', 'currency' => 'PKR', 'price_usd' => '', 'play_product_id' => 'coins_5000', 'is_active' => 0, 'sort' => 5,
        ])->assertRedirect(route('admin.coin-packs'));
        $mega->refresh();
        $this->assertSame('Mega+', $mega->name);
        $this->assertSame(349900, $mega->price_minor);
        $this->assertNull($mega->price_usd_minor);
        $this->assertFalse($mega->is_active);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'coin_pack.updated', 'target_id' => $mega->id]);

        // Referenced by a payment: refused. Unreferenced: deleted and audited.
        $this->payment('fulfilled', ['fulfilled_at' => now()]);
        $this->actingAs($this->admin)->delete(route('admin.coin-packs.destroy', $this->pack))->assertRedirect(route('admin.coin-packs'))->assertSessionHas('error');
        $this->assertDatabaseHas(CoinPack::class, ['id' => $this->pack->id]);
        $this->actingAs($this->admin)->delete(route('admin.coin-packs.destroy', $mega))->assertRedirect(route('admin.coin-packs'))->assertSessionHas('status');
        $this->assertDatabaseMissing(CoinPack::class, ['id' => $mega->id]);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'coin_pack.deleted']);
    }

    public function test_the_money_overview_renders_the_numbers_flags_and_warnings(): void
    {
        AppSetting::put(['stripe_enabled' => true, 'play_enabled' => true]);
        config(['services.stripe.secret' => null, 'services.play.service_account' => null]);
        $this->payment('fulfilled', ['fulfilled_at' => now()->subDay()]);
        $this->payment('fulfilled', ['fulfilled_at' => now()->subDays(2), 'gateway' => 'stripe', 'gateway_ref' => 'cs_a', 'amount_minor' => 1999, 'currency' => 'USD']);
        $this->payment('paid', ['paid_at' => now()->subHour(), 'gateway' => 'stripe', 'gateway_ref' => 'cs_b']);
        $this->payment('failed', ['gateway' => 'stripe', 'gateway_ref' => 'cs_c', 'meta' => ['reason' => 'amount_mismatch', 'reported' => ['amount_minor' => 4990, 'currency' => 'PKR']]]);
        $this->payment('refunded', ['refunded_at' => now(), 'meta' => ['reason' => 'admin_refund', 'shortfall' => 120]]);
        $this->payment('review');
        app(CoinService::class)->credit($this->user, 750, 'purchase', null, 'payment:z');

        $this->actingAs($this->admin)->get(route('admin.money'))->assertOk()
            ->assertSee('Rs 499')                     // revenue in the main currency
            ->assertSee('Card (Stripe)')->assertSee('$19.99')
            ->assertSee('Coins in wallets')->assertSee('750')
            ->assertSee('Payments to review')
            ->assertSee('Paid but not delivered')
            ->assertSee('Amount mismatches')
            ->assertSee('120 coins short')
            ->assertSee('Stripe is on but the secret key is missing')
            ->assertSee('Google Play is on but the service-account JSON is missing')
            ->assertSee('The scheduler has not run');

        AppSetting::put(['paid_enabled' => false]);
        $this->actingAs($this->admin)->get(route('admin.money'))->assertOk()->assertSee('Paid features are switched off');
    }

    public function test_pay_check_answers_per_gateway(): void
    {
        // Not configured: a clear message, no call.
        $this->actingAs($this->admin)->postJson(route('admin.settings.pay-check', 'stripe'))->assertOk()->assertJsonPath('ok', false)->assertJsonPath('message', 'Enter the Stripe secret key and save first.');
        $this->actingAs($this->admin)->postJson(route('admin.settings.pay-check', 'paypal'))->assertOk()->assertJsonPath('ok', false);
        $this->actingAs($this->admin)->postJson(route('admin.settings.pay-check', 'play'))->assertOk()->assertJsonPath('ok', false)->assertJsonPath('message', 'Paste the service-account JSON and save first.');
        $this->actingAs($this->admin)->postJson(route('admin.settings.pay-check', 'bitcoin'))->assertNotFound();

        // Stripe: balance retrieve; PayPal: token exchange.
        config(['services.stripe.secret' => 'sk_test_x', 'services.paypal.client_id' => 'cid', 'services.paypal.secret' => 'sec', 'services.paypal.mode' => 'sandbox']);
        $stripe = Mockery::mock(StripeGateway::class, [app(PaymentService::class)])->makePartial();
        $stripe->shouldReceive('retrieveBalance')->once()->andReturn(['livemode' => false]);
        $this->app->instance(StripeGateway::class, $stripe);
        $this->actingAs($this->admin)->postJson(route('admin.settings.pay-check', 'stripe'))->assertOk()->assertJsonPath('ok', true)->assertJsonPath('message', 'Stripe answers (test mode). Add the webhook signing secret so payments are confirmed.');

        // The check always asks for a fresh token: first accepted, then refused.
        Http::fake(['api-m.sandbox.paypal.com/v1/oauth2/token' => Http::sequence()->push(['access_token' => 'A21', 'expires_in' => 3600])->push(['error' => 'invalid_client'], 401)]);
        $this->actingAs($this->admin)->postJson(route('admin.settings.pay-check', 'paypal'))->assertOk()->assertJsonPath('ok', true)->assertJsonPath('message', 'PayPal accepted the credentials (sandbox).');
        $this->actingAs($this->admin)->postJson(route('admin.settings.pay-check', 'paypal'))->assertOk()->assertJsonPath('ok', false)->assertJsonPath('message', fn ($m) => str_contains($m, 'PayPal refused the credentials'));
    }

    public function test_non_admins_get_403_on_every_money_route(): void
    {
        $payment = $this->payment('review');
        $routes = [
            ['get', route('admin.money')], ['get', route('admin.payments')], ['get', route('admin.payments.show', $payment)], ['get', route('admin.payments.proof', $payment)],
            ['post', route('admin.payments.approve', $payment)], ['post', route('admin.payments.reject', $payment)], ['post', route('admin.payments.refund', $payment)], ['post', route('admin.payments.retry', $payment)],
            ['get', route('admin.coin-packs')], ['post', route('admin.coin-packs.store')], ['put', route('admin.coin-packs.update', $this->pack)], ['delete', route('admin.coin-packs.destroy', $this->pack)],
            ['post', route('admin.settings.pay-check', 'stripe')],
        ];
        foreach ($routes as [$method, $url]) {
            $this->actingAs($this->user)->{$method}($url)->assertForbidden();
        }
        $this->assertSame('review', $payment->fresh()->status);
        $this->assertSame(0, AdminAuditLog::query()->count());
        $this->assertSame(0, CoinTransaction::query()->count());
    }
}
