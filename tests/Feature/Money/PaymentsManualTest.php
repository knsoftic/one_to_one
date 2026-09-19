<?php

namespace Tests\Feature\Money;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\CoinPack;
use App\Models\CoinTransaction;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\MoneyNotification;
use App\Services\CoinService;
use App\Services\PaymentService;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Manual transfers (Y2): JazzCash / EasyPaisa / bank — begin, screenshot, admin approve/reject,
 * refund with clawback, sweeps, and the platform rule (the app never gets web methods).
 */
class PaymentsManualTest extends TestCase
{
    use RefreshDatabase;

    private const APP_UA = 'Mozilla/5.0 (Linux; Android 14) One2OneApp/1.0';

    private User $user;

    private User $admin;

    private CoinPack $pack;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        AppSetting::put([
            'paid_enabled' => true, 'manual_enabled' => true,
            'manual_jazzcash' => 'JazzCash 0300-1234567 (Hunario)', 'manual_bank' => 'Meezan Bank PK12MEZN0000000000001234',
            'manual_note' => 'Write your username in the transfer note.',
        ]);
        $this->user = User::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->pack = CoinPack::query()->create(['name' => 'Starter', 'coins' => 500, 'bonus_coins' => 50, 'price_minor' => 49900, 'currency' => 'PKR', 'is_active' => true, 'sort' => 1]);
    }

    private function begin(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson(route('pay.begin'), array_merge([
            'purpose' => 'coins', 'item_id' => $this->pack->id, 'gateway' => 'manual', 'client_token' => (string) Str::uuid(),
        ], $overrides));
    }

    private function proof(Payment $payment, array $overrides = [])
    {
        return $this->actingAs($this->user)->post(route('pay.proof', $payment), array_merge([
            'method' => 'jazzcash', 'ref' => 'TXN-778899', 'note' => 'Sent from my JazzCash', 'screenshot' => UploadedFile::fake()->image('proof.jpg', 800, 1200),
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_begin_creates_a_pending_payment_with_a_snapshot_and_instructions(): void
    {
        $response = $this->begin()->assertOk()
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.gateway', 'manual')
            ->assertJsonPath('payment.purpose', 'coins')
            ->assertJsonPath('payment.amount_minor', 49900)
            ->assertJsonPath('payment.currency', 'PKR')
            ->assertJsonPath('payment.coins', 550)
            ->assertJsonPath('instructions.methods.jazzcash.label', 'JazzCash')
            ->assertJsonPath('instructions.methods.bank.text', 'Meezan Bank PK12MEZN0000000000001234')
            ->assertJsonPath('instructions.note', 'Write your username in the transfer note.')
            ->assertJsonPath('instructions.amount', 49900)
            ->assertJsonPath('instructions.amount_display', 'Rs 499')
            ->assertJsonMissingPath('instructions.methods.easypaisa')
            ->assertJsonMissingPath('redirect');

        $payment = Payment::query()->find($response->json('payment.id'));
        $this->assertSame('web', $payment->platform);
        $this->assertSame($this->user->id, $payment->user_id);
        $this->assertSame(36, strlen($payment->uuid));
        $this->assertSame(0, CoinTransaction::query()->count());
    }

    public function test_the_same_client_token_returns_the_same_payment(): void
    {
        $token = (string) Str::uuid();
        $first = $this->begin(['client_token' => $token])->json('payment.id');
        $second = $this->begin(['client_token' => $token])->assertOk()->json('payment.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::query()->count());

        // A new token for the same item supersedes the older pending attempt.
        $third = $this->begin()->json('payment.id');
        $this->assertNotSame($first, $third);
        $this->assertSame('cancelled', Payment::query()->find($first)->status);
        $this->assertSame('superseded', Payment::query()->find($first)->meta['reason']);
    }

    public function test_the_screenshot_goes_to_the_private_disk_and_moves_the_payment_to_review(): void
    {
        $payment = Payment::query()->find($this->begin()->json('payment.id'));

        $this->proof($payment)->assertOk()
            ->assertJsonPath('payment.status', 'review')
            ->assertJsonPath('payment.manual_method', 'jazzcash')
            ->assertJsonPath('payment.proof_ref', 'TXN-778899')
            ->assertJsonPath('payment.has_proof', true);

        $payment->refresh();
        $this->assertStringStartsWith('payments/'.$payment->id.'/', $payment->proof_path);
        Storage::disk('local')->assertExists($payment->proof_path);
        Storage::disk('public')->assertMissing($payment->proof_path);
        $this->assertSame('Sent from my JazzCash', $payment->proof_note);

        // A second upload is refused: it is no longer pending.
        $this->proof($payment)->assertStatus(409)->assertJsonPath('code', 'not_pending');
        // A method the admin wrote no instructions for, or a missing screenshot, is refused.
        $this->proof(Payment::query()->find($this->begin()->json('payment.id')), ['method' => 'easypaisa'])->assertStatus(422)->assertJsonPath('code', 'unknown_method');
        $this->actingAs($this->user)->postJson(route('pay.proof', Payment::query()->where('status', 'pending')->first()), ['method' => 'bank', 'ref' => 'x'])->assertStatus(422)->assertJsonValidationErrors('screenshot');
    }

    public function test_pay_show_is_owner_only_and_returns_the_status_with_instructions(): void
    {
        $payment = Payment::query()->find($this->begin()->json('payment.id'));

        $this->actingAs($this->user)->getJson(route('pay.show', $payment))->assertOk()
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.review_note', null)
            ->assertJsonPath('instructions.methods.jazzcash.label', 'JazzCash');

        $this->actingAs(User::factory()->create())->getJson(route('pay.show', $payment))->assertForbidden();

        $this->actingAs($this->user)->deleteJson(route('pay.cancel', $payment))->assertOk()->assertJsonPath('payment.status', 'cancelled');
        $this->actingAs($this->user)->deleteJson(route('pay.cancel', $payment))->assertStatus(409)->assertJsonPath('code', 'not_pending');
    }

    public function test_approving_credits_the_coins_once_and_a_second_approval_is_refused(): void
    {
        Notification::fake();
        $payment = Payment::query()->find($this->begin()->json('payment.id'));
        $this->proof($payment);

        $this->actingAs($this->admin)->post(route('admin.payments.approve', $payment), ['note' => 'Matches the JazzCash statement'])
            ->assertRedirect(route('admin.payments.show', $payment))->assertSessionHas('status');

        $payment->refresh();
        $this->assertSame('fulfilled', $payment->status);
        $this->assertSame('manual:'.$payment->id, $payment->gateway_ref);
        $this->assertNotNull($payment->paid_at);
        $this->assertNotNull($payment->fulfilled_at);
        $this->assertSame($this->admin->id, $payment->reviewed_by);
        $this->assertSame('Matches the JazzCash statement', $payment->review_note);
        $this->assertSame(550, Wallet::query()->find($this->user->id)->balance);
        $this->assertDatabaseHas(CoinTransaction::class, ['idempotency_key' => 'payment:'.$payment->id, 'type' => 'purchase', 'amount' => 550]);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'payment.approved', 'admin_id' => $this->admin->id, 'target_id' => $payment->id]);
        Notification::assertSentTo($this->user, MoneyNotification::class, fn (MoneyNotification $n) => $n->type === 'coins_credited' && str_contains($n->data['title'], 'approved'));

        // Twice: refused, still one ledger row, same balance.
        $this->actingAs($this->admin)->post(route('admin.payments.approve', $payment))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(1, CoinTransaction::query()->count());
        $this->assertSame(550, Wallet::query()->find($this->user->id)->balance);

        // Idempotent at the service level too: fulfil() again changes nothing.
        app(PaymentService::class)->fulfil($payment);
        $this->assertSame(1, CoinTransaction::query()->count());
    }

    public function test_approving_a_plan_payment_activates_it_through_the_plan_service(): void
    {
        $plan = Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'period' => 'month', 'price_minor' => 99900, 'currency' => 'PKR', 'is_active' => true]);
        $sub = Subscription::query()->create(['user_id' => $this->user->id, 'plan_id' => $plan->id, 'status' => 'active', 'source' => 'manual', 'benefits' => [], 'starts_at' => now(), 'ends_at' => now()->addMonth()]);

        $payment = Payment::query()->find($this->begin(['purpose' => 'plan', 'item_id' => $plan->id])->assertOk()->assertJsonPath('payment.amount_minor', 99900)->assertJsonPath('payment.coins', null)->json('payment.id'));
        $this->proof($payment, ['method' => 'bank']);

        $this->mock(PlanService::class, function ($mock) use ($plan, $sub) {
            $mock->shouldReceive('activate')->once()
                ->withArgs(fn (User $u, Plan $p, string $source, ?Payment $pay) => $u->id === $this->user->id && $p->id === $plan->id && $source === 'manual' && $pay?->purpose === 'plan')
                ->andReturn($sub);
        });

        $this->actingAs($this->admin)->post(route('admin.payments.approve', $payment))->assertRedirect(route('admin.payments.show', $payment));

        $payment->refresh();
        $this->assertSame('fulfilled', $payment->status);
        $this->assertSame($sub->id, $payment->subscription_id);
        $this->assertSame(0, CoinTransaction::query()->count());
    }

    public function test_rejecting_stores_the_note_credits_nothing_and_tells_the_person(): void
    {
        Notification::fake();
        $payment = Payment::query()->find($this->begin()->json('payment.id'));
        $this->proof($payment);

        $this->actingAs($this->admin)->post(route('admin.payments.reject', $payment), [])->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->post(route('admin.payments.reject', $payment), ['note' => 'The screenshot shows Rs 49, not Rs 499'])->assertRedirect(route('admin.payments.show', $payment));

        $payment->refresh();
        $this->assertSame('rejected', $payment->status);
        $this->assertSame('The screenshot shows Rs 49, not Rs 499', $payment->review_note);
        $this->assertSame(0, CoinTransaction::query()->count());
        $this->assertNull(Wallet::query()->find($this->user->id));
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'payment.rejected', 'target_id' => $payment->id]);
        Notification::assertSentTo($this->user, MoneyNotification::class, fn (MoneyNotification $n) => $n->type === 'payment_rejected' && str_contains($n->data['body'], 'Rs 49, not Rs 499'));

        $this->actingAs($this->user)->getJson(route('pay.show', $payment))->assertOk()->assertJsonPath('payment.status', 'rejected')->assertJsonPath('payment.review_note', 'The screenshot shows Rs 49, not Rs 499');

        // Rejected is final: cannot be approved afterwards.
        $this->actingAs($this->admin)->post(route('admin.payments.approve', $payment))->assertSessionHas('error');
        $this->assertSame('rejected', $payment->fresh()->status);
    }

    public function test_a_duplicate_transfer_id_is_allowed_but_flagged_to_the_admin(): void
    {
        $first = Payment::query()->find($this->begin()->json('payment.id'));
        $this->proof($first, ['ref' => 'SAME-123']);
        $this->actingAs($this->admin)->post(route('admin.payments.approve', $first));

        $second = Payment::query()->find($this->begin()->json('payment.id'));
        $this->proof($second, ['ref' => 'SAME-123'])->assertOk()->assertJsonPath('payment.status', 'review');

        $this->actingAs($this->admin)->get(route('admin.payments.show', $second))->assertOk()
            ->assertSee('was also used on')
            ->assertSee(route('admin.payments.show', $first), false);
        $this->actingAs($this->admin)->get(route('admin.payments.show', $first))->assertOk()->assertSee('was also used on');
    }

    public function test_a_refund_claws_the_coins_back_and_records_the_shortfall(): void
    {
        Notification::fake();
        $payment = Payment::query()->find($this->begin()->json('payment.id'));
        $this->proof($payment);
        $this->actingAs($this->admin)->post(route('admin.payments.approve', $payment));
        // 550 credited; 400 already spent.
        app(CoinService::class)->debit($this->user, 400, 'promotion_hold', null, 'promo:1:hold');

        $this->actingAs($this->admin)->post(route('admin.payments.refund', $payment), [])->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->post(route('admin.payments.refund', $payment), ['note' => 'JazzCash reversed the transfer'])
            ->assertRedirect(route('admin.payments.show', $payment))->assertSessionHas('status', fn ($m) => str_contains($m, '400 coins had already been spent'));

        $payment->refresh();
        $this->assertSame('refunded', $payment->status);
        $this->assertSame('admin_refund', $payment->meta['reason']);
        $this->assertSame(400, $payment->meta['shortfall']);
        $this->assertNotNull($payment->refunded_at);
        $this->assertSame(0, Wallet::query()->find($this->user->id)->balance);
        $this->assertDatabaseHas(CoinTransaction::class, ['idempotency_key' => 'payment:'.$payment->id.':refund', 'type' => 'payment_refund', 'amount' => -150]);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'payment.refunded', 'target_id' => $payment->id]);
        $audit = AdminAuditLog::query()->where('action', 'payment.refunded')->first();
        $this->assertSame(400, $audit->meta['shortfall']);
        Notification::assertSentTo($this->user, MoneyNotification::class, fn (MoneyNotification $n) => $n->type === 'payment_refunded');

        // A second refund is a no-op (no second clawback), and the flag shows on the page.
        $this->actingAs($this->admin)->post(route('admin.payments.refund', $payment), ['note' => 'again'])->assertRedirect();
        $this->assertSame(1, CoinTransaction::query()->where('type', 'payment_refund')->count());
        $this->actingAs($this->admin)->get(route('admin.payments.show', $payment))->assertOk()->assertSee('400 coins short');
    }

    public function test_the_sweep_cancels_stale_pending_payments_but_keeps_review_ones(): void
    {
        AppSetting::put(['manual_expire_hours' => 48]);
        $stale = Payment::query()->find($this->begin()->json('payment.id'));
        $inReview = Payment::query()->find($this->begin(['client_token' => (string) Str::uuid()], User::factory()->create())->json('payment.id'));
        $this->actingAs($inReview->user)->post(route('pay.proof', $inReview), ['method' => 'bank', 'ref' => 'R1', 'screenshot' => UploadedFile::fake()->image('p.png')], ['Accept' => 'application/json'])->assertOk();
        Payment::query()->whereIn('id', [$stale->id, $inReview->id])->update(['created_at' => now()->subHours(49)]);
        $fresh = Payment::query()->find($this->begin(['client_token' => (string) Str::uuid()], User::factory()->create())->json('payment.id'));

        $this->artisan('chat:payments-sweep')->expectsOutputToContain('1 stale payment(s) cancelled')->assertSuccessful();

        $this->assertSame('cancelled', $stale->fresh()->status);
        $this->assertSame('no_proof', $stale->fresh()->meta['reason']);
        $this->assertSame('review', $inReview->fresh()->status);
        $this->assertSame('pending', $fresh->fresh()->status);
    }

    public function test_the_app_user_agent_gets_no_web_methods_and_the_web_gets_no_play(): void
    {
        AppSetting::put(['stripe_enabled' => true, 'paypal_enabled' => true, 'play_enabled' => true]);
        config(['services.stripe.secret' => 'sk_test_x', 'services.paypal.client_id' => 'cid', 'services.paypal.secret' => 'sec']);
        $this->pack->update(['price_usd_minor' => 199, 'play_product_id' => 'coins_500']);

        foreach (['manual', 'stripe', 'paypal'] as $gateway) {
            $this->actingAs($this->user)->withHeader('User-Agent', self::APP_UA)->postJson(route('pay.begin'), [
                'purpose' => 'coins', 'item_id' => $this->pack->id, 'gateway' => $gateway, 'client_token' => (string) Str::uuid(),
            ])->assertStatus(422)->assertJsonPath('code', 'gateway_unavailable');
        }
        $this->flushHeaders();
        $this->begin(['gateway' => 'play'])->assertStatus(422)->assertJsonPath('code', 'gateway_unavailable');
        $this->assertSame(0, Payment::query()->count());

        $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => self::APP_UA]);
        $this->assertSame(['play'], app(PaymentService::class)->methodsFor($this->user, $request, $this->pack));
        $this->assertSame(['manual', 'stripe', 'paypal'], app(PaymentService::class)->methodsFor($this->user, Request::create('/'), $this->pack));
    }

    public function test_everything_is_404_while_paid_features_are_off(): void
    {
        $payment = Payment::query()->find($this->begin()->json('payment.id'));
        AppSetting::put(['paid_enabled' => false]);

        $this->begin()->assertNotFound();
        $this->actingAs($this->user)->getJson(route('pay.show', $payment))->assertNotFound();
        $this->proof($payment)->assertNotFound();
        $this->actingAs($this->user)->get(route('pay.return', ['payment' => $payment, 'gateway' => 'stripe']))->assertNotFound();
        // The admin can still see it.
        $this->actingAs($this->admin)->get(route('admin.payments.show', $payment))->assertOk();
    }

    public function test_inactive_items_and_bad_input_are_refused(): void
    {
        $this->pack->update(['is_active' => false]);
        $this->begin()->assertStatus(422)->assertJsonPath('code', 'item_unavailable');
        $this->begin(['item_id' => 999])->assertStatus(422)->assertJsonPath('code', 'invalid_item');
        $this->begin(['client_token' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors('client_token');
        $this->begin(['gateway' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors('gateway');
    }

    public function test_the_proof_route_is_admin_only_and_audited(): void
    {
        $payment = Payment::query()->find($this->begin()->json('payment.id'));
        $this->proof($payment);

        $this->actingAs($this->user)->get(route('admin.payments.proof', $payment))->assertForbidden();
        $this->get(route('admin.payments.proof', $payment))->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.payments.proof', $payment))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'payment.proof_viewed', 'admin_id' => $this->admin->id, 'target_id' => $payment->id]);
    }

    public function test_proofs_are_purged_after_the_retention_period(): void
    {
        AppSetting::put(['proof_keep_days' => 30]);
        $done = Payment::query()->find($this->begin()->json('payment.id'));
        $this->proof($done);
        $this->actingAs($this->admin)->post(route('admin.payments.approve', $done));
        $open = Payment::query()->find($this->begin(['client_token' => (string) Str::uuid()], User::factory()->create())->json('payment.id'));
        $this->actingAs($open->user)->post(route('pay.proof', $open), ['method' => 'bank', 'ref' => 'R2', 'screenshot' => UploadedFile::fake()->image('p.png')], ['Accept' => 'application/json'])->assertOk();
        $donePath = $done->fresh()->proof_path;
        $openPath = $open->fresh()->proof_path;
        Payment::query()->whereIn('id', [$done->id, $open->id])->update(['updated_at' => now()->subDays(31)]);

        $this->assertSame(1, app(PaymentService::class)->purgeProofs());

        Storage::disk('local')->assertMissing($donePath);
        $this->assertNull($done->fresh()->proof_path);
        Storage::disk('local')->assertExists($openPath);
        $this->actingAs($this->admin)->get(route('admin.payments.proof', $done))->assertNotFound();
    }
}
