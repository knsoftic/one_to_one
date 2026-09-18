<?php

namespace Tests\Feature\Money;

use App\Exceptions\InsufficientCoinsException;
use App\Exceptions\WalletFrozenException;
use App\Models\AdminAuditLog;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CoinService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The coin ledger (Y2): every move locks the wallet, honours its idempotency key, and can never
 * take the balance below zero.
 */
class CoinLedgerTest extends TestCase
{
    use RefreshDatabase;

    private CoinService $coins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coins = app(CoinService::class);
    }

    public function test_credits_and_debits_write_ledger_rows_with_the_balance_after(): void
    {
        $user = User::factory()->create();

        $credit = $this->coins->credit($user, 500, 'purchase', null, 'payment:1', 'Bought 500 coins');
        $debit = $this->coins->debit($user, 120, 'promotion_hold', null, 'promo:1:hold');

        $this->assertSame(500, $credit->balance_after);
        $this->assertSame(380, $debit->balance_after);
        $this->assertSame(-120, $debit->amount);
        $wallet = Wallet::query()->find($user->id);
        $this->assertSame(380, $wallet->balance);
        $this->assertSame(500, $wallet->purchased_total);
        $this->assertSame(120, $wallet->spent_total);
        $this->assertSame(380, $this->coins->summary($user)['balance']);
    }

    public function test_a_debit_over_the_balance_throws_and_leaves_the_wallet_unchanged(): void
    {
        $user = User::factory()->create();
        $this->coins->credit($user, 100, 'purchase', null, 'payment:1');

        try {
            $this->coins->debit($user, 101, 'badge', null, 'badge:x');
            $this->fail('Expected InsufficientCoinsException');
        } catch (InsufficientCoinsException $e) {
            $this->assertSame(101, $e->needed);
            $this->assertSame(100, $e->balance);
        }

        $this->assertSame(100, Wallet::query()->find($user->id)->balance);
        $this->assertSame(1, CoinTransaction::query()->where('user_id', $user->id)->count());
    }

    public function test_the_database_refuses_a_forced_underflow(): void
    {
        $user = User::factory()->create();
        $this->coins->credit($user, 10, 'purchase', null, 'payment:1');

        $this->expectException(QueryException::class);
        DB::table('wallets')->where('user_id', $user->id)->update(['balance' => DB::raw('balance - 11')]);
    }

    public function test_the_same_key_twice_moves_coins_once(): void
    {
        $user = User::factory()->create();

        $first = $this->coins->credit($user, 50, 'referral', null, 'referral:9:referrer', withdrawable: true);
        $second = $this->coins->credit($user, 50, 'referral', null, 'referral:9:referrer', withdrawable: true);

        $this->assertTrue($first->is($second));
        $this->assertSame(50, Wallet::query()->find($user->id)->balance);
        $this->assertSame(1, CoinTransaction::query()->count());
    }

    public function test_debits_spend_purchased_coins_before_earned_ones_and_refunds_restore_the_share(): void
    {
        $user = User::factory()->create();
        $this->coins->credit($user, 100, 'purchase', null, 'payment:1');
        $this->coins->credit($user, 100, 'referral', null, 'referral:1:referrer', withdrawable: true);

        // 150 = all 100 purchased + 50 earned.
        $debit = $this->coins->debit($user, 150, 'promotion_hold', $user, 'promo:1:hold');
        $this->assertSame(-50, $debit->withdrawable_delta);
        $wallet = Wallet::query()->find($user->id);
        $this->assertSame(50, $wallet->balance);
        $this->assertSame(50, $wallet->withdrawable);

        // Refund 90 of the 150: the earned share comes back in proportion (50/150 of 90 = 30).
        $refund = $this->coins->refund($user, 90, 'promotion_refund', $user, 'promo:1:refund', $debit);
        $this->assertSame(30, $refund->withdrawable_delta);
        $wallet->refresh();
        $this->assertSame(140, $wallet->balance);
        $this->assertSame(80, $wallet->withdrawable);
    }

    public function test_clawback_stops_at_zero_and_records_the_shortfall(): void
    {
        $user = User::factory()->create();
        $this->coins->credit($user, 30, 'purchase', null, 'payment:1');

        $row = $this->coins->clawback($user, 100, 'payment_refund', null, 'payment:1:refund', 'Refunded');

        $this->assertSame(-30, $row->amount);
        $this->assertSame(70, $row->meta['shortfall']);
        $this->assertSame(0, Wallet::query()->find($user->id)->balance);

        // Nothing left: no ledger row, but the shortfall is still reported.
        $again = $this->coins->clawback($user, 5, 'payment_refund', null, 'payment:2:refund', 'Refunded');
        $this->assertFalse($again->exists);
        $this->assertSame(5, $again->meta['shortfall']);
    }

    public function test_a_frozen_wallet_blocks_debits_but_accepts_credits(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->coins->credit($user, 100, 'purchase', null, 'payment:1');

        $this->coins->freeze($admin, $user, true);
        $this->assertTrue(Wallet::query()->find($user->id)->frozen);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'wallet.frozen', 'admin_id' => $admin->id]);

        $this->expectException(WalletFrozenException::class);
        try {
            $this->coins->debit($user, 10, 'badge', null, 'badge:1');
        } finally {
            $this->coins->credit($user, 10, 'referral', null, 'referral:2:referrer', withdrawable: true);
            $this->assertSame(110, Wallet::query()->find($user->id)->balance);
        }
    }

    public function test_an_admin_adjustment_is_idempotent_per_token_and_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->coins->adjust($admin, $user, 200, 'Compensation', 'tok-1');
        $this->coins->adjust($admin, $user, 200, 'Compensation', 'tok-1');
        $this->coins->adjust($admin, $user, -50, 'Correction', 'tok-2');

        $this->assertSame(150, Wallet::query()->find($user->id)->balance);
        $this->assertSame(2, CoinTransaction::query()->where('type', 'admin_adjust')->count());
        $this->assertSame(2, AdminAuditLog::query()->where('action', 'coins.adjusted')->count());
    }

    public function test_concurrent_debits_never_go_below_zero(): void
    {
        $user = User::factory()->create();
        $this->coins->credit($user, 100, 'purchase', null, 'payment:1');

        // Two debits that together exceed the balance: at most one can succeed, in any order.
        $ok = 0;
        foreach (['promo:1:hold', 'promo:2:hold'] as $key) {
            try {
                $this->coins->debit($user, 70, 'promotion_hold', null, $key);
                $ok++;
            } catch (InsufficientCoinsException) {
            }
        }

        $this->assertSame(1, $ok);
        $this->assertSame(30, Wallet::query()->find($user->id)->balance);
    }
}
