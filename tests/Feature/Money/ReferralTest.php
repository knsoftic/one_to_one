<?php

namespace Tests\Feature\Money;

use App\Models\AppSetting;
use App\Models\CoinTransaction;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\MoneyNotification;
use App\Services\AccountService;
use App\Services\CoinService;
use App\Services\ReferralService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Refer & earn (Y2): the link remembers the inviter, sign-up attaches a pending row, and the
 * reward is paid exactly once — when the friend verifies their number and every check passes.
 */
class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private ReferralService $referrals;

    private CoinService $coins;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        AppSetting::put(['paid_enabled' => true, 'referral_enabled' => true, 'referral_reward' => 50, 'referral_welcome' => 0]);
        $this->referrals = app(ReferralService::class);
        $this->coins = app(CoinService::class);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['phone' => '+92300'.str_pad((string) ++self::$seq, 7, '0', STR_PAD_LEFT)], $attributes));
    }

    private function request(string $ip = '10.0.0.1'): Request
    {
        return Request::create('/register', 'POST', [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    /** A friend who signed up with the code (pending, not yet verified). */
    private function invite(User $referrer, string $ip = '10.0.0.1', array $attributes = []): User
    {
        $friend = $this->user($attributes);
        $this->referrals->attach($friend, $this->referrals->codeFor($referrer), $this->request($ip));

        return $friend;
    }

    /** The friend verifies their number: the null → set transition fires the User::updated hook. */
    private function verify(User $friend): User
    {
        $friend->forceFill(['phone_verified_at' => now()])->save();

        return $friend->fresh();
    }

    public function test_the_code_is_made_once_and_the_link_points_at_it(): void
    {
        $user = $this->user();
        $this->assertNull($user->referral_code);

        $code = $this->referrals->codeFor($user);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);
        $this->assertSame($code, $user->fresh()->referral_code);
        $this->assertSame($code, $this->referrals->codeFor($user->fresh()));
        $this->assertSame(route('referral.join', ['code' => $code]), $this->referrals->link($user));
        $this->assertTrue($this->referrals->resolve(strtolower($code))->is($user));
        $this->assertNull($this->referrals->resolve('NOPE'));
    }

    public function test_the_link_stores_the_session_and_register_attaches_a_pending_row(): void
    {
        $referrer = $this->user();
        $code = $this->referrals->codeFor($referrer);

        $this->get(route('referral.join', ['code' => $code]))
            ->assertRedirect(route('register', ['ref' => $code]))
            ->assertSessionHas('referral_code', $code)
            ->assertCookie('ref', $code);

        $this->get(route('register', ['ref' => $code]))->assertOk()->assertSee('Invited by')->assertSee($referrer->name);

        $this->post('/register', ['name' => 'Sara Khan', 'phone' => '0300 7654321', 'password' => 'sara2026'])->assertSessionHasNoErrors();

        $friend = User::query()->where('phone', '+923007654321')->sole();
        $referral = Referral::query()->where('referred_id', $friend->id)->sole();
        $this->assertSame('pending', $referral->status);
        $this->assertSame($referrer->id, $referral->referrer_id);
        $this->assertSame($code, $referral->code);
        $this->assertSame(hash('sha256', '127.0.0.1'.config('app.key')), $referral->ip_hash);
        $this->assertSame($referrer->id, $friend->referred_by);

        // Signing up alone pays nothing.
        $this->assertSame(0, (int) $this->coins->summary($referrer)['balance']);
        $this->assertSame(0, CoinTransaction::query()->count());
    }

    public function test_a_signed_in_person_opening_the_link_goes_to_their_chats(): void
    {
        $referrer = $this->user();
        $code = $this->referrals->codeFor($referrer);

        // Unknown code, as a guest: a plain sign-up link, nothing remembered.
        $this->get(route('referral.join', ['code' => 'ZZZZ9999']))->assertRedirect(route('register'))->assertSessionMissing('referral_code');

        $this->actingAs($this->user())->get(route('referral.join', ['code' => $code]))->assertRedirect(route('chat.index'));
    }

    public function test_the_reward_is_paid_once_when_the_number_is_verified(): void
    {
        $referrer = $this->user();
        $friend = $this->invite($referrer);

        $this->verify($friend);

        $referral = Referral::query()->where('referred_id', $friend->id)->sole();
        $this->assertSame('rewarded', $referral->status);
        $this->assertSame(50, $referral->referrer_coins);
        $this->assertSame(0, $referral->referred_coins);
        $this->assertNotNull($referral->rewarded_at);

        $summary = $this->coins->summary($referrer);
        $this->assertSame(50, $summary['balance']);
        $this->assertSame(50, $summary['withdrawable']);   // referral coins are the "Earned" bucket
        $this->assertSame(50, $summary['earned_total']);
        $row = CoinTransaction::query()->where('idempotency_key', "referral:{$referral->id}:referrer")->sole();
        $this->assertSame('referral', $row->type);
        $this->assertSame(50, $row->withdrawable_delta);
        $this->assertSame(0, (int) $this->coins->summary($friend)['balance']);
        Notification::assertSentTo($referrer, MoneyNotification::class, fn (MoneyNotification $n) => $n->type === 'referral_rewarded');

        // Saving the date again (or a second verification) pays nothing more.
        $friend->fresh()->forceFill(['phone_verified_at' => now()->addMinute()])->save();
        $friend->fresh()->forceFill(['phone_verified_at' => null])->save();
        $friend->fresh()->forceFill(['phone_verified_at' => now()])->save();
        $this->assertSame(1, CoinTransaction::query()->where('user_id', $referrer->id)->count());
        $this->assertSame(50, $this->coins->summary($referrer)['balance']);
    }

    public function test_the_change_number_path_rewards_too(): void
    {
        $referrer = $this->user();
        $friend = $this->invite($referrer);

        app(AccountService::class)->changePhone($friend, '+923009998887', verified: true);

        $this->assertSame('rewarded', Referral::query()->where('referred_id', $friend->id)->sole()->status);
        $this->assertSame(50, $this->coins->summary($referrer)['balance']);
    }

    public function test_welcome_coins_go_to_the_friend_and_are_not_withdrawable(): void
    {
        AppSetting::put(['referral_welcome' => 20]);
        $referrer = $this->user();
        $friend = $this->invite($referrer);

        $this->verify($friend);

        $referral = Referral::query()->where('referred_id', $friend->id)->sole();
        $this->assertSame(20, $referral->referred_coins);
        $summary = $this->coins->summary($friend);
        $this->assertSame(20, $summary['balance']);
        $this->assertSame(0, $summary['withdrawable']);
        $this->assertSame('referral_welcome', CoinTransaction::query()->where('idempotency_key', "referral:{$referral->id}:referred")->sole()->type);
        Notification::assertSentTo($friend, MoneyNotification::class);
    }

    public function test_self_referral_and_the_same_phone_are_ignored(): void
    {
        $user = $this->user();
        $code = $this->referrals->codeFor($user);

        $this->assertNull($this->referrals->attach($user, $code, $this->request()));
        // Same phone is impossible through sign-up (unique), so mimic it on the model only.
        $twin = $this->user();
        $twin->phone = $user->phone;
        $this->assertNull($this->referrals->attach($twin, $code, $this->request()));
        $this->assertSame(0, Referral::query()->count());
    }

    public function test_unknown_codes_and_an_inactive_inviter_attach_nothing(): void
    {
        $friend = $this->user();
        $this->assertNull($this->referrals->attach($friend, 'ABCD2345', $this->request()));
        $this->assertNull($this->referrals->attach($friend, null, $this->request()));

        $banned = $this->user(['status' => User::STATUS_BANNED]);
        $this->assertNull($this->referrals->attach($friend, $this->referrals->codeFor($banned), $this->request()));
        $this->assertSame(0, Referral::query()->count());
        $this->assertNull($friend->fresh()->referred_by);
    }

    public function test_one_referral_per_referred_account_ever(): void
    {
        $a = $this->user();
        $b = $this->user();
        $friend = $this->invite($a);

        $this->assertNull($this->referrals->attach($friend, $this->referrals->codeFor($b), $this->request()));
        $this->assertSame(1, Referral::query()->count());
        $this->assertSame($a->id, $friend->fresh()->referred_by);

        $this->expectException(UniqueConstraintViolationException::class);
        Referral::query()->create(['referrer_id' => $b->id, 'referred_id' => $friend->id, 'code' => 'X', 'status' => 'pending']);
    }

    public function test_the_ip_cap_voids_with_a_reason(): void
    {
        AppSetting::put(['referral_ip_cap' => 2]);
        $referrer = $this->user();
        $this->verify($this->invite($referrer, '10.0.0.9'));
        $this->verify($this->invite($referrer, '10.0.0.9'));
        $this->assertSame(100, $this->coins->summary($referrer)['balance']);

        $third = $this->verify($this->invite($referrer, '10.0.0.9'));

        $referral = Referral::query()->where('referred_id', $third->id)->sole();
        $this->assertSame('void', $referral->status);
        $this->assertSame('ip_cap', $referral->void_reason);
        $this->assertNotNull($referral->voided_at);
        $this->assertSame(100, $this->coins->summary($referrer)['balance']);

        // A different connection is fine.
        $this->verify($this->invite($referrer, '10.0.0.10'));
        $this->assertSame(150, $this->coins->summary($referrer)['balance']);
    }

    public function test_the_daily_cap_voids_with_a_reason(): void
    {
        AppSetting::put(['referral_daily_cap' => 1]);
        $referrer = $this->user();
        $this->verify($this->invite($referrer, '10.0.1.1'));
        $second = $this->verify($this->invite($referrer, '10.0.1.2'));

        $referral = Referral::query()->where('referred_id', $second->id)->sole();
        $this->assertSame('void', $referral->status);
        $this->assertSame('daily_cap', $referral->void_reason);
        $this->assertSame(50, $this->coins->summary($referrer)['balance']);
    }

    public function test_a_banned_inviter_gets_nothing(): void
    {
        $referrer = $this->user();
        $friend = $this->invite($referrer);
        $referrer->forceFill(['status' => User::STATUS_BANNED, 'banned_at' => now()])->save();

        $this->verify($friend);

        $referral = Referral::query()->where('referred_id', $friend->id)->sole();
        $this->assertSame('void', $referral->status);
        $this->assertSame('referrer_inactive', $referral->void_reason);
        $this->assertSame(0, CoinTransaction::query()->count());
    }

    public function test_referrals_switched_off_attach_nothing_and_void_pending_rows(): void
    {
        $referrer = $this->user();
        $friend = $this->invite($referrer);
        $code = $this->referrals->codeFor($referrer);

        AppSetting::put(['referral_enabled' => false]);

        $this->assertFalse($this->referrals->enabled());
        $this->assertNull($this->referrals->attach($this->user(), $code, $this->request()));
        $this->get(route('referral.join', ['code' => $code]))->assertRedirect(route('register'))->assertSessionMissing('referral_code');
        $this->actingAs($referrer)->get(route('referral.show'))->assertNotFound();

        $this->verify($friend);
        $referral = Referral::query()->where('referred_id', $friend->id)->sole();
        $this->assertSame('void', $referral->status);
        $this->assertSame('disabled', $referral->void_reason);
        $this->assertSame(0, (int) $this->coins->summary($referrer)['balance']);
    }

    public function test_deleting_the_account_within_seven_days_claws_the_coins_back(): void
    {
        AppSetting::put(['referral_welcome' => 20]);
        $referrer = $this->user();
        $friend = $this->verify($this->invite($referrer));
        $this->coins->debit($referrer, 30, 'badge', null, 'badge:x'); // spent some already

        $this->referrals->voidForDeletedAccount($friend);

        $referral = Referral::query()->where('referred_id', $friend->id)->sole();
        $this->assertSame('void', $referral->status);
        $this->assertSame('deleted_early', $referral->void_reason);
        $this->assertSame(0, (int) $this->coins->summary($referrer)['balance']);
        $this->assertSame(0, (int) $this->coins->summary($friend)['balance']);
        $referrerRow = CoinTransaction::query()->where('idempotency_key', "referral:{$referral->id}:void:referrer")->sole();
        $this->assertSame('referral_void', $referrerRow->type);
        $this->assertSame(-20, $referrerRow->amount);
        $this->assertSame(30, $referrerRow->meta['shortfall']);
        $this->assertSame(-20, CoinTransaction::query()->where('idempotency_key', "referral:{$referral->id}:void:referred")->sole()->amount);

        // Voiding again changes nothing (reward, welcome, my debit, two clawbacks = 5 rows).
        $this->referrals->void($referral->fresh(), 'admin');
        $this->assertSame(5, CoinTransaction::query()->count());
    }

    public function test_deleting_the_account_later_keeps_the_reward(): void
    {
        $referrer = $this->user();
        $friend = $this->verify($this->invite($referrer));
        Referral::query()->where('referred_id', $friend->id)->update(['rewarded_at' => now()->subDays(8)]);

        $this->referrals->voidForDeletedAccount($friend);

        $this->assertSame('rewarded', Referral::query()->where('referred_id', $friend->id)->sole()->status);
        $this->assertSame(50, $this->coins->summary($referrer)['balance']);
    }

    public function test_the_summary_and_the_screen_show_what_was_earned(): void
    {
        $referrer = $this->user();
        $rewarded = $this->verify($this->invite($referrer, '10.0.2.1'));
        $pending = $this->invite($referrer, '10.0.2.2');

        $summary = $this->referrals->summary($referrer);
        $this->assertSame($referrer->fresh()->referral_code, $summary['code']);
        $this->assertSame(50, $summary['reward']);
        $this->assertSame(2, $summary['invited']);
        $this->assertSame(1, $summary['rewarded']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(50, $summary['coins_earned']);
        $this->assertSame([$pending->name, $rewarded->name], array_column($summary['list'], 'name'));

        $this->actingAs($referrer)->get(route('referral.show'))->assertOk()
            ->assertJsonPath('code', $summary['code'])
            ->assertJsonPath('link', route('referral.join', ['code' => $summary['code']]))
            ->assertJsonPath('rewarded', 1)
            ->assertJsonPath('list.1.status', 'rewarded');
    }

    public function test_the_verified_hook_is_a_no_op_for_people_without_a_referral(): void
    {
        $user = $this->user();
        $this->verify($user);
        $this->assertSame(0, Referral::query()->count());
        $this->assertSame(0, CoinTransaction::query()->count());
    }
}
