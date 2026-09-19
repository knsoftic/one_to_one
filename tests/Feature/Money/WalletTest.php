<?php

namespace Tests\Feature\Money;

use App\Models\AppSetting;
use App\Models\CoinPack;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\User;
use App\Services\CoinService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Settings › Wallet (Y2): what the screen gets, the paginated history, the "coming soon"
 * withdrawal, and everything answering 404 while paid features are off.
 */
class WalletTest extends TestCase
{
    use RefreshDatabase;

    private CoinService $coins;

    protected function setUp(): void
    {
        parent::setUp();
        AppSetting::put(['paid_enabled' => true, 'manual_enabled' => true, 'manual_jazzcash' => '0300 1234567 (Ali)']);
        $this->coins = app(CoinService::class);
    }

    private function pack(array $overrides = []): CoinPack
    {
        return CoinPack::query()->create(array_merge(['name' => 'Starter', 'coins' => 500, 'bonus_coins' => 50, 'price_minor' => 49900, 'currency' => 'PKR', 'play_product_id' => 'coins_500', 'sort' => 1], $overrides));
    }

    private function payment(User $user, array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'purpose' => 'coins', 'gateway' => 'manual', 'status' => 'pending',
            'platform' => 'web', 'amount_minor' => 49900, 'currency' => 'PKR', 'coins' => 550,
        ], $overrides));
    }

    public function test_the_wallet_screen_gets_summary_packs_methods_pending_and_history(): void
    {
        $user = User::factory()->create();
        $this->coins->credit($user, 300, 'purchase', null, 'payment:1', 'Bought 300 coins');
        $this->coins->credit($user, 50, 'referral', null, 'referral:1:referrer', 'Invited Sara', withdrawable: true);
        $pack = $this->pack();
        $this->pack(['name' => 'Hidden', 'is_active' => false, 'play_product_id' => 'coins_x']);
        $this->payment($user, ['status' => 'review', 'proof_ref' => 'TX-1', 'manual_method' => 'jazzcash']);
        $this->payment($user, ['status' => 'fulfilled', 'gateway_ref' => 'manual:9']);

        $response = $this->actingAs($user)->getJson(route('wallet.show'))->assertOk();

        $response->assertJsonStructure(['summary' => ['balance', 'withdrawable', 'purchased', 'earned_total', 'purchased_total', 'spent_total', 'frozen', 'withdraw_enabled'], 'packs', 'methods', 'pending', 'play' => ['enabled', 'accountHash', 'minAppCode'], 'history' => ['data', 'page', 'has_more'], 'refund_url'])
            ->assertJsonPath('summary.balance', 350)
            ->assertJsonPath('summary.withdrawable', 50)
            ->assertJsonPath('summary.purchased', 300)
            ->assertJsonPath('summary.withdraw_enabled', false)
            ->assertJsonCount(1, 'packs')
            ->assertJsonPath('packs.0.id', $pack->id)
            ->assertJsonPath('packs.0.total_coins', 550)
            ->assertJsonPath('packs.0.price_display', 'Rs 499')
            ->assertJsonPath('packs.0.play_product_id', 'coins_500')
            ->assertJsonPath('methods', ['manual'])
            ->assertJsonCount(1, 'pending')
            ->assertJsonPath('pending.0.status', 'review')
            ->assertJsonPath('pending.0.item', '550 coins')
            ->assertJsonPath('pending.0.amount_display', 'Rs 499')
            ->assertJsonPath('pending.0.proof_ref', 'TX-1')
            ->assertJsonPath('play.accountHash', hash('sha256', $user->id.config('app.key')))
            ->assertJsonPath('history.data.0.type', 'referral')
            ->assertJsonPath('history.data.0.amount', 50)
            ->assertJsonPath('history.data.0.withdrawable_delta', 50)
            ->assertJsonPath('history.data.0.note', 'Invited Sara')
            ->assertJsonPath('history.data.1.label', 'Bought coins')
            ->assertJsonPath('history.has_more', false)
            ->assertJsonPath('refund_url', route('legal', 'refunds'));

        // The Android app only ever gets Google Play (off here).
        $this->actingAs($user)->getJson(route('wallet.show'), ['User-Agent' => 'Mozilla/5.0 One2OneApp/1.0'])->assertOk()->assertJsonPath('methods', []);
    }

    public function test_the_history_is_paginated(): void
    {
        $user = User::factory()->create();
        for ($i = 1; $i <= 35; $i++) {
            $this->coins->credit($user, 1, 'purchase', null, "payment:{$i}");
        }

        $this->actingAs($user)->getJson(route('wallet.show'))->assertOk()
            ->assertJsonCount(30, 'history.data')->assertJsonPath('history.has_more', true)->assertJsonPath('history.next_page', 2);

        $this->actingAs($user)->getJson(route('wallet.history', ['page' => 2]))->assertOk()
            ->assertJsonCount(5, 'data')->assertJsonPath('page', 2)->assertJsonPath('has_more', false)->assertJsonPath('next_page', null);

        // Only my own rows.
        $this->actingAs(User::factory()->create())->getJson(route('wallet.history'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_withdrawals_are_coming_soon(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('wallet.withdraw'))->assertStatus(409)->assertJsonPath('code', 'coming_soon');

        AppSetting::put(['wallet_withdraw_enabled' => true]);
        $this->actingAs($user)->getJson(route('wallet.show'))->assertJsonPath('summary.withdraw_enabled', true);
        $this->actingAs($user)->postJson(route('wallet.withdraw'))->assertStatus(409)->assertJsonPath('code', 'coming_soon');
    }

    public function test_every_route_answers_404_while_paid_features_are_off(): void
    {
        AppSetting::put(['paid_enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('wallet.show'))->assertNotFound();
        $this->actingAs($user)->getJson(route('wallet.history'))->assertNotFound();
        $this->actingAs($user)->postJson(route('wallet.withdraw'))->assertNotFound();
        $this->actingAs($user)->postJson(route('badge.buy'), ['client_token' => (string) Str::uuid()])->assertNotFound();
        $this->actingAs($user)->getJson(route('referral.show'))->assertNotFound();

        // Balances still exist and admin pages still read them.
        $this->coins->credit($user, 10, 'purchase', null, 'payment:1');
        $this->assertSame(10, $this->coins->summary($user)['balance']);
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.users.show', ['user' => $user, 'tab' => 'money']))->assertOk()->assertSee('Coin history');
    }

    public function test_the_routes_need_a_signed_in_person(): void
    {
        $this->getJson(route('wallet.show'))->assertUnauthorized();
        $this->getJson(route('referral.show'))->assertUnauthorized();
    }

    public function test_the_referral_link_is_remembered_and_sign_up_attaches_a_pending_referral(): void
    {
        $referrer = User::factory()->create(['phone' => '+923001112233']);
        $code = app(ReferralService::class)->codeFor($referrer);

        $this->get(route('referral.join', ['code' => $code]))->assertRedirect(route('register', ['ref' => $code]))->assertSessionHas('referral_code', $code);
        $this->post('/register', ['name' => 'Bilal Ahmed', 'phone' => '0300 4445566', 'password' => 'bilal1234'])->assertSessionHasNoErrors();

        $friend = User::query()->where('phone', '+923004445566')->sole();
        $this->assertSame('pending', Referral::query()->where('referred_id', $friend->id)->sole()->status);
        $this->assertSame($referrer->id, $friend->referred_by);
        $this->assertSame(0, (int) $this->coins->summary($referrer)['balance']);
        $this->assertNull(session('referral_code'));
    }
}
