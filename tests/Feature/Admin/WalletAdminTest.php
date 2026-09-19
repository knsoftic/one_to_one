<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\CoinTransaction;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BadgeService;
use App\Services\CoinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → person → Money (Y2): adjust coins, freeze the wallet, grant/remove the badge — every
 * action audited — and the tab that shows it all.
 */
class WalletAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private CoinService $coins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create(['name' => 'Ayesha Khan']);
        $this->coins = app(CoinService::class);
    }

    private function moneyTab(): string
    {
        return route('admin.users.show', ['user' => $this->user, 'tab' => 'money']);
    }

    public function test_adjusting_coins_is_idempotent_per_token_and_audited(): void
    {
        $token = (string) Str::uuid();

        $this->actingAs($this->admin)->post(route('admin.users.coins.adjust', $this->user), ['amount' => 100, 'note' => 'Goodwill', 'token' => $token])
            ->assertRedirect($this->moneyTab())->assertSessionHas('status');

        $this->assertSame(100, Wallet::query()->find($this->user->id)->balance);
        $row = CoinTransaction::query()->where('idempotency_key', 'admin:'.$token)->sole();
        $this->assertSame('admin_adjust', $row->type);
        $this->assertSame($this->admin->id, $row->created_by);
        $this->assertSame('Goodwill', $row->note);
        $log = AdminAuditLog::query()->where('action', 'coins.adjusted')->sole();
        $this->assertSame($this->user->id, $log->target_id);
        $this->assertSame(100, $log->meta['amount']);

        // A double submit with the same token changes nothing.
        $this->actingAs($this->admin)->post(route('admin.users.coins.adjust', $this->user), ['amount' => 100, 'note' => 'Goodwill', 'token' => $token])->assertRedirect();
        $this->assertSame(100, Wallet::query()->find($this->user->id)->balance);
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'coins.adjusted')->count());

        // Removing coins.
        $this->actingAs($this->admin)->post(route('admin.users.coins.adjust', $this->user), ['amount' => -30, 'note' => 'Refund outside', 'token' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame(70, Wallet::query()->find($this->user->id)->balance);
        $this->assertSame(-30, AdminAuditLog::query()->where('action', 'coins.adjusted')->latest('id')->first()->meta['amount']);
    }

    public function test_adjust_validates_and_refuses_more_than_they_have(): void
    {
        $this->actingAs($this->admin)->from($this->moneyTab())->post(route('admin.users.coins.adjust', $this->user), ['amount' => 0, 'note' => 'x', 'token' => (string) Str::uuid()])
            ->assertRedirect($this->moneyTab())->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->from($this->moneyTab())->post(route('admin.users.coins.adjust', $this->user), ['amount' => 5, 'note' => '', 'token' => (string) Str::uuid()])
            ->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->from($this->moneyTab())->post(route('admin.users.coins.adjust', $this->user), ['amount' => 5, 'note' => 'x', 'token' => 'not-a-uuid'])
            ->assertSessionHasErrors('token');

        $this->actingAs($this->admin)->from($this->moneyTab())->post(route('admin.users.coins.adjust', $this->user), ['amount' => -50, 'note' => 'Too much', 'token' => (string) Str::uuid()])
            ->assertRedirect($this->moneyTab())->assertSessionHasErrors('amount');
        $this->assertSame(0, CoinTransaction::query()->count());
        $this->assertSame(0, AdminAuditLog::query()->count());
    }

    public function test_freezing_blocks_spending_and_is_audited(): void
    {
        $this->coins->credit($this->user, 100, 'purchase', null, 'payment:1');

        $this->actingAs($this->admin)->post(route('admin.users.wallet.freeze', $this->user), ['frozen' => 1])->assertRedirect($this->moneyTab());
        $this->assertTrue(Wallet::query()->find($this->user->id)->frozen);
        $log = AdminAuditLog::query()->where('action', 'wallet.frozen')->sole();
        $this->assertTrue($log->meta['frozen']);

        $this->actingAs($this->admin)->from($this->moneyTab())->post(route('admin.users.coins.adjust', $this->user), ['amount' => -10, 'note' => 'x', 'token' => (string) Str::uuid()])
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->get($this->moneyTab())->assertOk()->assertSee('Frozen')->assertSee('Unfreeze wallet');

        $this->actingAs($this->admin)->post(route('admin.users.wallet.freeze', $this->user), ['frozen' => 0])->assertRedirect($this->moneyTab());
        $this->assertFalse(Wallet::query()->find($this->user->id)->frozen);
        $this->assertSame(2, AdminAuditLog::query()->where('action', 'wallet.frozen')->count());
        $this->actingAs($this->admin)->post(route('admin.users.wallet.freeze', $this->user), [])->assertSessionHasErrors('frozen');
    }

    public function test_granting_and_removing_the_badge_is_audited(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.badge.grant', $this->user), ['days' => 30])->assertRedirect($this->moneyTab())->assertSessionHas('status');
        $this->assertTrue(now()->addDays(30)->isSameDay($this->user->fresh()->verified_until));
        $this->assertSame('admin', $this->user->fresh()->verified_source);
        $this->assertSame(30, AdminAuditLog::query()->where('action', 'badge.granted')->sole()->meta['days']);

        $this->actingAs($this->admin)->post(route('admin.users.badge.grant', $this->user), [])->assertRedirect($this->moneyTab());
        $this->assertTrue(BadgeService::isLifetime($this->user->fresh()->verified_until));
        $this->actingAs($this->admin)->post(route('admin.users.badge.grant', $this->user), ['days' => 0])->assertSessionHasErrors('days');

        $this->actingAs($this->admin)->get($this->moneyTab())->assertOk()->assertSee('Remove badge')->assertSee('lifetime');

        $this->actingAs($this->admin)->delete(route('admin.users.badge.remove', $this->user))->assertRedirect($this->moneyTab());
        $this->assertNull($this->user->fresh()->verified_until);
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'badge.removed')->count());
    }

    public function test_the_money_tab_renders_wallet_ledger_payments_and_referrals(): void
    {
        $this->coins->credit($this->user, 250, 'purchase', null, 'payment:1', 'Bought 250 coins');
        $this->coins->debit($this->user, 40, 'badge', null, 'badge:1', 'Verified badge');
        Payment::query()->create(['uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'purpose' => 'coins', 'gateway' => 'manual', 'status' => 'review', 'platform' => 'web', 'amount_minor' => 49900, 'currency' => 'PKR', 'coins' => 250]);
        $friend = User::factory()->create(['name' => 'Bilal Raza']);
        Referral::query()->create(['referrer_id' => $this->user->id, 'referred_id' => $friend->id, 'code' => 'ABCD2345', 'status' => 'void', 'void_reason' => 'ip_cap']);
        $this->user->forceFill(['referral_code' => 'ABCD2345'])->save();
        Plan::query()->create(['name' => 'Pro', 'slug' => 'pro', 'period' => 'month', 'price_minor' => 49900, 'currency' => 'PKR']);

        $this->actingAs($this->admin)->get($this->moneyTab())->assertOk()
            ->assertSee('Coin history')
            ->assertSee('Adjust coins')
            ->assertSee('Freeze wallet')
            ->assertSee('Grant plan')
            ->assertSee('Verified badge')
            ->assertSee('Bought 250 coins')
            ->assertSee('+250')
            ->assertSee('-40')
            ->assertSee('Rs 499')
            ->assertSee('Review')
            ->assertSee('Bilal Raza')
            ->assertSee('Too many from one connection')
            ->assertSee('ABCD2345')
            ->assertSee('name="token"', false);

        // The ledger paginates on its own query key.
        for ($i = 2; $i <= 25; $i++) {
            $this->coins->credit($this->user, 1, 'purchase', null, "payment:{$i}", "Row {$i}");
        }
        $this->actingAs($this->admin)->get($this->moneyTab().'&ledger_page=2')->assertOk()->assertSee('Bought 250 coins')->assertSee('of 26 entries');
        $this->actingAs($this->admin)->get($this->moneyTab())->assertOk()->assertDontSee('Bought 250 coins')->assertSee('Row 25');
    }

    public function test_only_admins_can_touch_money(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)->get($this->moneyTab())->assertForbidden();
        $this->actingAs($other)->post(route('admin.users.coins.adjust', $this->user), ['amount' => 100, 'note' => 'x', 'token' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($other)->post(route('admin.users.wallet.freeze', $this->user), ['frozen' => 1])->assertForbidden();
        $this->actingAs($other)->post(route('admin.users.badge.grant', $this->user), ['days' => 30])->assertForbidden();
        $this->actingAs($other)->delete(route('admin.users.badge.remove', $this->user))->assertForbidden();
        $this->assertSame(0, Wallet::query()->count());

        // The paid master switch does not gate the admin side.
        AppSetting::put(['paid_enabled' => false]);
        $this->actingAs($this->admin)->post(route('admin.users.coins.adjust', $this->user), ['amount' => 5, 'note' => 'x', 'token' => (string) Str::uuid()])->assertRedirect($this->moneyTab());
        $this->assertSame(5, Wallet::query()->find($this->user->id)->balance);
    }
}
