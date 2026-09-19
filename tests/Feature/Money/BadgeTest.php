<?php

namespace Tests\Feature\Money;

use App\Http\Resources\UserResource;
use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\CoinTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\BadgeService;
use App\Services\CoinService;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The verified badge (Y2): bought with coins (idempotent per client token), granted by an admin,
 * or included in the active plan — and shown by every user payload.
 */
class BadgeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '3f1c2b4e-5d6a-4f7b-8c9d-0e1f2a3b4c5d';

    private CoinService $coins;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        AppSetting::put(['paid_enabled' => true, 'badge_coin_price' => 500, 'badge_days' => 365]);
        $this->coins = app(CoinService::class);
    }

    private function funded(int $coins = 1200): User
    {
        $user = User::factory()->create();
        $this->coins->credit($user, $coins, 'purchase', null, 'payment:'.$user->id);

        return $user;
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::query()->create(array_merge(['name' => 'Pro', 'slug' => 'pro', 'period' => 'month', 'price_minor' => 49900, 'currency' => 'PKR', 'verified_badge' => true], $overrides));
    }

    public function test_buying_sets_verified_until_and_charges_once_per_token(): void
    {
        $user = $this->funded();

        $this->travelTo(now()->startOfMinute());
        $this->actingAs($user)->postJson(route('badge.buy'), ['client_token' => self::TOKEN])->assertOk()
            ->assertJsonPath('badge.verified', true)
            ->assertJsonPath('badge.source', 'coins')
            ->assertJsonPath('wallet.balance', 700);

        $user->refresh();
        $this->assertTrue(now()->addDays(365)->isSameMinute($user->verified_until));
        $this->assertSame('coins', $user->verified_source);
        $row = CoinTransaction::query()->where('idempotency_key', "badge:{$user->id}:".self::TOKEN)->sole();
        $this->assertSame(-500, $row->amount);
        $this->assertSame('badge', $row->type);
        $this->assertTrue(app(BadgeService::class)->isVerified($user));

        // The same tap again: nothing more is charged.
        $this->actingAs($user)->postJson(route('badge.buy'), ['client_token' => self::TOKEN])->assertOk()->assertJsonPath('wallet.balance', 700);
        $this->assertSame(1, CoinTransaction::query()->where('type', 'badge')->count());

        // A new token extends the current period rather than restarting it.
        $until = $user->fresh()->verified_until;
        $this->actingAs($user)->postJson(route('badge.buy'), ['client_token' => '9f1c2b4e-5d6a-4f7b-8c9d-0e1f2a3b4c5d'])->assertOk()->assertJsonPath('wallet.balance', 200);
        $this->assertTrue($until->copy()->addDays(365)->equalTo($user->fresh()->verified_until));
    }

    public function test_not_enough_coins_and_bad_tokens_are_refused(): void
    {
        $user = $this->funded(100);

        $this->actingAs($user)->postJson(route('badge.buy'), ['client_token' => self::TOKEN])->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_coins')->assertJsonPath('needed', 500)->assertJsonPath('balance', 100);
        $this->assertNull($user->fresh()->verified_until);

        $this->actingAs($user)->postJson(route('badge.buy'), ['client_token' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('client_token');
        $this->actingAs($user)->postJson(route('badge.buy'))->assertStatus(422)->assertJsonValidationErrors('client_token');
    }

    public function test_a_frozen_wallet_cannot_buy(): void
    {
        $user = $this->funded();
        $this->coins->freeze(User::factory()->admin()->create(), $user, true);

        $this->actingAs($user)->postJson(route('badge.buy'), ['client_token' => self::TOKEN])->assertStatus(422)->assertJsonPath('code', 'wallet_frozen');
    }

    public function test_lifetime_when_days_is_zero_and_a_lifetime_badge_cannot_be_bought_again(): void
    {
        AppSetting::put(['badge_days' => 0]);
        $user = $this->funded();

        $this->actingAs($user)->postJson(route('badge.buy'), ['client_token' => self::TOKEN])->assertOk()
            ->assertJsonPath('badge.lifetime', true)->assertJsonPath('badge.until', null)->assertJsonPath('badge.purchasable', false);
        $this->assertTrue(BadgeService::isLifetime($user->fresh()->verified_until));

        $this->actingAs($user)->postJson(route('badge.buy'), ['client_token' => '9f1c2b4e-5d6a-4f7b-8c9d-0e1f2a3b4c5d'])->assertStatus(409)->assertJsonPath('code', 'already_lifetime');
        $this->assertSame(700, $this->coins->summary($user)['balance']);
    }

    public function test_price_zero_means_not_for_sale(): void
    {
        AppSetting::put(['badge_coin_price' => 0]);
        $user = $this->funded();

        $this->actingAs($user)->postJson(route('badge.buy'), ['client_token' => self::TOKEN])->assertNotFound();
        $this->assertFalse(app(BadgeService::class)->state($user)['purchasable']);
    }

    public function test_paid_features_off_hides_the_route(): void
    {
        AppSetting::put(['paid_enabled' => false]);
        $this->actingAs($this->funded())->postJson(route('badge.buy'), ['client_token' => self::TOKEN])->assertNotFound();
    }

    public function test_the_plan_badge_needs_no_coins_and_follows_the_subscription(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $plans = app(PlanService::class);
        $this->assertFalse(app(BadgeService::class)->isVerified($user));

        $sub = $plans->grant($admin, $user, $this->plan(), 30);

        $state = app(BadgeService::class)->state($user->fresh());
        $this->assertTrue($state['verified']);
        $this->assertSame('plan', $state['source']);
        $this->assertTrue($state['purchasable']);   // coins can still extend after the plan
        $this->assertNull($user->fresh()->verified_until);

        $plans->revoke($sub, 'admin', $admin);
        $this->assertFalse(app(BadgeService::class)->isVerified($user->fresh()));

        // A plan without the benefit gives no badge.
        $plans->grant($admin, $user, $this->plan(['slug' => 'lite', 'name' => 'Lite', 'verified_badge' => false]), 30);
        $this->assertFalse(app(BadgeService::class)->isVerified($user->fresh()));
    }

    public function test_the_expired_plan_badge_disappears_while_a_coin_badge_stays(): void
    {
        $user = $this->funded();
        $admin = User::factory()->admin()->create();
        $plans = app(PlanService::class);
        $plans->grant($admin, $user, $this->plan(), 1);
        app(BadgeService::class)->buy($user, self::TOKEN);
        $this->assertSame('plan', app(BadgeService::class)->state($user->fresh())['source']);

        $this->travel(2)->days();
        $plans->expire();

        $state = app(BadgeService::class)->state($user->fresh());
        $this->assertTrue($state['verified']);
        $this->assertSame('coins', $state['source']);
    }

    public function test_user_resource_and_the_blade_component_show_the_tick(): void
    {
        $user = User::factory()->create();
        $viewer = User::factory()->create();
        $request = Request::create('/');
        $request->setUserResolver(fn () => $viewer);

        $this->assertFalse((new UserResource($user))->resolve($request)['verified']);
        $this->assertSame('', trim(view('components.verified-badge', ['user' => $user, 'hint' => true])->render()));

        $user->forceFill(['verified_until' => now()->addDays(10), 'verified_source' => 'coins'])->save();
        $this->assertTrue((new UserResource($user->fresh()))->resolve($request)['verified']);
        $html = view('components.verified-badge', ['user' => $user->fresh(), 'hint' => true])->render();
        $this->assertStringContainsString('verified-badge', $html);
        $this->assertStringContainsString('Verified until '.now()->addDays(10)->format('j M Y'), $html);
    }

    public function test_an_admin_grants_and_removes_the_badge_with_an_audit_trail(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $badges = app(BadgeService::class);

        $badges->grant($admin, $user, 30);
        $this->assertTrue(now()->addDays(30)->isSameDay($user->fresh()->verified_until));
        $this->assertSame('admin', $user->fresh()->verified_source);
        $log = AdminAuditLog::query()->where('action', 'badge.granted')->sole();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame($user->id, $log->target_id);
        $this->assertSame(30, $log->meta['days']);

        $badges->grant($admin, $user->fresh(), null);
        $this->assertTrue(BadgeService::isLifetime($user->fresh()->verified_until));
        $this->assertTrue(app(BadgeService::class)->state($user->fresh())['lifetime']);

        $badges->remove($admin, $user->fresh());
        $this->assertNull($user->fresh()->verified_until);
        $this->assertNull($user->fresh()->verified_source);
        $this->assertFalse(app(BadgeService::class)->isVerified($user->fresh()));
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'badge.removed')->count());
    }
}
