<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\CoinTransaction;
use App\Models\Referral;
use App\Models\User;
use App\Services\CoinService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Admin → Money → Referrals (Y2): top referrers, suspicious groups, the void list, and Void.
 */
class ReferralsAdminTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private User $admin;

    private ReferralService $referrals;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        AppSetting::put(['paid_enabled' => true, 'referral_enabled' => true, 'referral_reward' => 50, 'referral_welcome' => 10, 'referral_ip_cap' => 10]);
        $this->admin = User::factory()->admin()->create();
        $this->referrals = app(ReferralService::class);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['phone' => '+92300'.str_pad((string) ++self::$seq, 7, '0', STR_PAD_LEFT)], $attributes));
    }

    private function rewarded(User $referrer, string $ip = '10.0.0.1'): Referral
    {
        $friend = $this->user();
        $this->referrals->attach($friend, $this->referrals->codeFor($referrer), Request::create('/register', 'POST', [], [], [], ['REMOTE_ADDR' => $ip]));
        $friend->forceFill(['phone_verified_at' => now()])->save();

        return Referral::query()->where('referred_id', $friend->id)->sole();
    }

    public function test_the_page_lists_top_referrers_suspicious_groups_and_void_reasons(): void
    {
        $star = $this->user(['name' => 'Star Inviter']);
        $this->rewarded($star, '10.9.9.9');
        $this->rewarded($star, '10.9.9.9');
        $this->rewarded($star, '10.9.9.9');
        $other = $this->user(['name' => 'One Invite']);
        $voided = $this->rewarded($other, '10.1.1.1');
        $this->referrals->void($voided, 'daily_cap');

        $this->actingAs($this->admin)->get(route('admin.referrals'))->assertOk()
            ->assertSee('Star Inviter')
            ->assertSee('3 rewarded')
            ->assertSee('150 coins')
            ->assertSee('3 sign-ups')
            ->assertSee(substr(hash('sha256', '10.9.9.9'.config('app.key')), 0, 12))
            ->assertSee('Daily limit reached')
            ->assertSee('Void');

        // The status filter narrows the table (the top-referrers panel stays).
        $this->actingAs($this->admin)->get(route('admin.referrals', ['status' => 'void']))->assertOk()
            ->assertSee('1 void referrals')->assertSee('One Invite')->assertSee('Daily limit reached');
        $this->actingAs($this->admin)->get(route('admin.referrals', ['status' => 'rewarded']))->assertOk()
            ->assertSee('3 rewarded referrals');
        $this->actingAs($this->admin)->get(route('admin.referrals', ['status' => 'pending']))->assertOk()
            ->assertSee('No referrals with this status');

        // The nav links to the page.
        $this->actingAs($this->admin)->get(route('admin.reports'))->assertSee(route('admin.referrals'), false);
    }

    public function test_the_page_warns_when_referrals_are_off(): void
    {
        AppSetting::put(['referral_enabled' => false]);
        $this->actingAs($this->admin)->get(route('admin.referrals'))->assertOk()->assertSee('switched off');
    }

    public function test_voiding_claws_the_coins_back_and_is_audited(): void
    {
        $referrer = $this->user();
        $referral = $this->rewarded($referrer);
        $coins = app(CoinService::class);
        $this->assertSame(50, $coins->summary($referrer)['balance']);
        $this->assertSame(10, $coins->summary($referral->referred)['balance']);

        $this->actingAs($this->admin)->from(route('admin.referrals'))->post(route('admin.referrals.void', $referral), ['note' => 'SIM farm'])
            ->assertRedirect(route('admin.referrals'))->assertSessionHas('status');

        $referral->refresh();
        $this->assertSame('void', $referral->status);
        $this->assertSame('admin', $referral->void_reason);
        $this->assertSame(0, $coins->summary($referrer)['balance']);
        $this->assertSame(0, $coins->summary($referral->referred)['balance']);
        $this->assertSame(-50, CoinTransaction::query()->where('idempotency_key', "referral:{$referral->id}:void:referrer")->sole()->amount);
        $this->assertSame(-10, CoinTransaction::query()->where('idempotency_key', "referral:{$referral->id}:void:referred")->sole()->amount);

        $log = AdminAuditLog::query()->where('action', 'referral.voided')->sole();
        $this->assertSame($this->admin->id, $log->admin_id);
        $this->assertSame('Referral', $log->target_type);
        $this->assertSame($referral->id, $log->target_id);
        $this->assertSame('SIM farm', $log->meta['note']);
        $this->assertSame(50, $log->meta['referrer_coins']);
        $this->assertStringContainsString('SIM farm', $log->description);

        // Voiding again: refused, nothing more taken.
        $this->actingAs($this->admin)->from(route('admin.referrals'))->post(route('admin.referrals.void', $referral), ['note' => 'again'])
            ->assertRedirect(route('admin.referrals'))->assertSessionHas('toast_error');
        $this->assertSame(4, CoinTransaction::query()->count());
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'referral.voided')->count());

        $this->actingAs($this->admin)->get(route('admin.referrals'))->assertOk()->assertSee('Reversed by an admin');
    }

    public function test_voiding_needs_a_note_and_a_pending_row_is_just_marked(): void
    {
        $referrer = $this->user();
        $friend = $this->user();
        $referral = $this->referrals->attach($friend, $this->referrals->codeFor($referrer), Request::create('/register'));

        $this->actingAs($this->admin)->from(route('admin.referrals'))->post(route('admin.referrals.void', $referral), ['note' => ''])->assertSessionHasErrors('note');
        $this->assertSame('pending', $referral->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.referrals.void', $referral), ['note' => 'Fake account'])->assertRedirect();
        $this->assertSame('void', $referral->fresh()->status);
        $this->assertSame(0, CoinTransaction::query()->count());

        // Verifying later pays nothing.
        $friend->forceFill(['phone_verified_at' => now()])->save();
        $this->assertSame(0, CoinTransaction::query()->count());
    }

    public function test_only_admins_see_referrals(): void
    {
        $referral = $this->rewarded($this->user());
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.referrals'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.referrals.void', $referral), ['note' => 'x'])->assertForbidden();
        $this->assertSame('rewarded', $referral->fresh()->status);
    }
}
