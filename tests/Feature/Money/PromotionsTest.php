<?php

namespace Tests\Feature\Money;

use App\Exceptions\PromotionException;
use App\Exceptions\WalletFrozenException;
use App\Models\AdCampaign;
use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\BusinessProfile;
use App\Models\CoinTransaction;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Status;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\MoneyNotification;
use App\Services\ChannelService;
use App\Services\CoinService;
use App\Services\CommunityService;
use App\Services\PromotionService;
use App\Services\StatusService;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Promotions (Y2): what a person can promote, the quote, submitting (row first, then the coin
 * hold), review, stopping and every refund rule.
 */
class PromotionsTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private PromotionService $promotions;

    private CoinService $coins;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        AppSetting::put(['paid_enabled' => true, 'promote_enabled' => true, 'ads_enabled' => true]);
        $this->promotions = app(PromotionService::class);
        $this->coins = app(CoinService::class);
    }

    private function user(array $attributes = [], int $coins = 1000): User
    {
        $user = User::factory()->create(array_merge(['phone' => '+92300'.str_pad((string) ++self::$seq, 7, '0', STR_PAD_LEFT)], $attributes));
        if ($coins > 0) {
            $this->coins->credit($user, $coins, 'purchase', null, 'payment:test:'.$user->id);
        }

        return $user;
    }

    private function makeStatus(User $owner, string $text = 'Hello'): Status
    {
        return app(StatusService::class)->createText($owner, $text, 'teal', 0);
    }

    private function channel(User $owner, string $name = 'My channel'): Conversation
    {
        return app(ChannelService::class)->create($owner, $name, 'Daily updates');
    }

    private function community(User $owner, string $name = 'My community'): Community
    {
        return app(CommunityService::class)->create($owner, $name, 'Neighbours', null);
    }

    private function data(array $overrides = []): array
    {
        return array_merge(['kind' => 'status', 'coins' => 100, 'client_token' => (string) Str::uuid()], $overrides);
    }

    private function balance(User $user): int
    {
        return (int) Wallet::query()->find($user->id)->balance;
    }

    /* ------------------------------------------------------------------ */
    /* Targets, quote, placements */
    /* ------------------------------------------------------------------ */

    public function test_targets_list_what_this_person_may_promote(): void
    {
        $user = $this->user();
        $other = $this->user();
        $status = $this->makeStatus($user, 'Fresh mangoes');
        $this->makeStatus($other, 'Not yours');
        $channel = $this->channel($user);
        $community = $this->community($user);
        BusinessProfile::query()->create(['user_id' => $user->id, 'category' => 'shop']);
        // A channel I only follow is not mine to promote.
        app(ChannelService::class)->follow($this->channel($other, 'Theirs'), $user);

        $targets = collect($this->promotions->targets($user));

        $this->assertSame(['status', 'channel', 'community', 'business'], $targets->pluck('kind')->unique()->values()->all());
        $this->assertSame($status->id, $targets->firstWhere('kind', 'status')['id']);
        $this->assertSame('Fresh mangoes', $targets->firstWhere('kind', 'status')['subtitle']);
        $this->assertSame([$channel->id], $targets->where('kind', 'channel')->pluck('id')->all());
        $this->assertSame($community->id, $targets->firstWhere('kind', 'community')['id']);
        $this->assertSame($user->id, $targets->firstWhere('kind', 'business')['id']);
        $this->assertFalse($targets->firstWhere('kind', 'status')['already_promoted']);

        // And the screen gets it all through one request.
        $this->actingAs($user)->getJson(route('promotions.index'))->assertOk()
            ->assertJsonPath('balance', 1000)
            ->assertJsonPath('rates.status', 100)
            ->assertJsonCount(4, 'targets')
            ->assertJsonPath('placements.status', ['status_list', 'chat_list']);
    }

    public function test_the_quote_uses_integer_division_in_the_apps_favour(): void
    {
        AppSetting::put(['promo_rate_link' => 150]);

        $this->assertSame(1000, $this->promotions->quote('status', 100)['views']);
        $this->assertSame(1666, $this->promotions->quote('link', 250)['views']); // 250000 / 150 = 1666.67

        $this->actingAs($this->user())->getJson(route('promotions.quote', ['kind' => 'link', 'coins' => 250]))
            ->assertOk()->assertJsonPath('views', 1666)->assertJsonPath('rate', 150)->assertJsonPath('min', 50);
    }

    public function test_placements_are_the_kinds_defaults_within_the_admins_choice(): void
    {
        $this->assertSame(['status_list', 'chat_list'], $this->promotions->placementsFor('status'));
        $this->assertSame(['channels', 'chat_list'], $this->promotions->placementsFor('channel'));
        $this->assertSame(['chat_list'], $this->promotions->placementsFor('link'));

        AppSetting::put(['promo_placements' => ['chat_list']]);
        $this->assertSame(['chat_list'], $this->promotions->placementsFor('status'));

        AppSetting::put(['promo_placements' => ['chat_list', 'status_list'], 'ad_placements' => ['status_list']]);
        $this->assertSame(['status_list'], $this->promotions->placementsFor('status'));
    }

    /* ------------------------------------------------------------------ */
    /* Create */
    /* ------------------------------------------------------------------ */

    public function test_create_inserts_the_campaign_then_holds_the_coins(): void
    {
        $user = $this->user();
        $status = $this->makeStatus($user, 'Big sale today');

        $promo = $this->promotions->create($user, $this->data(['target_id' => $status->id, 'coins' => 250]));

        $this->assertSame('pending', $promo->status);
        $this->assertSame('pending', $promo->review_status);
        $this->assertSame('status', $promo->kind);
        $this->assertSame('status', $promo->target_type);
        $this->assertSame($status->id, $promo->target_id);
        $this->assertSame(250, $promo->coins_spent);
        $this->assertSame(100, $promo->rate_per_1000);
        $this->assertSame(2500, $promo->view_budget);
        $this->assertSame(['status_list', 'chat_list'], $promo->placements);
        $this->assertSame(2, $promo->per_user_daily_cap);
        $this->assertSame(2, $promo->weight);
        $this->assertNull($promo->countries);
        $this->assertTrue($promo->ends_at->equalTo($status->expires_at));
        $this->assertSame(route('promotions.go', $promo), $promo->target_url);
        $this->assertSame('Big sale today', $promo->body);
        $this->assertSame('View status', $promo->cta_label);

        $hold = CoinTransaction::query()->where('idempotency_key', "promo:{$promo->id}:hold")->first();
        $this->assertNotNull($hold);
        $this->assertSame(-250, $hold->amount);
        $this->assertSame('AdCampaign', $hold->reference_type);
        $this->assertSame($promo->id, $hold->reference_id);
        $this->assertSame(750, $this->balance($user));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id, 'type' => MoneyNotification::class]);
    }

    public function test_not_enough_coins_means_422_and_no_row(): void
    {
        $user = $this->user(coins: 80);
        $status = $this->makeStatus($user);

        $this->actingAs($user)->postJson(route('promotions.store'), $this->data(['target_id' => $status->id, 'coins' => 100]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_coins')
            ->assertJsonPath('needed', 100)
            ->assertJsonPath('balance', 80);

        $this->assertSame(0, AdCampaign::query()->promotions()->count());
        $this->assertSame(80, $this->balance($user));
        $this->assertSame(1, CoinTransaction::query()->where('user_id', $user->id)->count()); // only the test credit
    }

    public function test_the_same_client_token_returns_the_same_campaign(): void
    {
        $user = $this->user();
        $status = $this->makeStatus($user);
        $data = $this->data(['target_id' => $status->id, 'coins' => 100]);

        $first = $this->actingAs($user)->postJson(route('promotions.store'), $data)->assertCreated()->json('promotion.id');
        $second = $this->actingAs($user)->postJson(route('promotions.store'), $data)->assertOk()->json('promotion.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, AdCampaign::query()->promotions()->count());
        $this->assertSame(900, $this->balance($user));
    }

    public function test_each_kind_builds_its_card_and_target(): void
    {
        $user = $this->user(coins: 5000);
        $channel = $this->channel($user, 'Cricket news');
        $community = $this->community($user, 'Block 7');
        BusinessProfile::query()->create(['user_id' => $user->id, 'category' => 'shop', 'description' => 'Fresh fruit']);

        $chan = $this->promotions->create($user, $this->data(['kind' => 'channel', 'target_id' => $channel->id]));
        $this->assertSame('conversation', $chan->target_type);
        $this->assertSame($channel->id, $chan->target_id);
        $this->assertSame(route('channels.link', $channel->fresh()->invite_token), $chan->target_url);
        $this->assertSame('Cricket news', $chan->title);
        $this->assertSame(['channels', 'chat_list'], $chan->placements);

        $comm = $this->promotions->create($user, $this->data(['kind' => 'community', 'target_id' => $community->id]));
        $this->assertSame('community', $comm->target_type);
        $this->assertSame(route('communities.join.show', $community->fresh()->invite_token), $comm->target_url);
        $this->assertSame('Join community', $comm->cta_label);

        $biz = $this->promotions->create($user, $this->data(['kind' => 'business', 'coins' => 120]));
        $this->assertSame('user', $biz->target_type);
        $this->assertSame($user->id, $biz->target_id);
        $this->assertSame(route('promotions.go', $biz), $biz->target_url);
        $this->assertSame(120, $biz->rate_per_1000);
        $this->assertSame(1000, $biz->view_budget);
        $this->assertStringContainsString('Fresh fruit', $biz->body);

        $card = $this->promotions->create($user, $this->data(['kind' => 'card', 'title' => 'Eid offer', 'url' => 'https://93.184.216.34/eid', 'coins' => 150]), UploadedFile::fake()->image('card.jpg', 800, 400));
        $this->assertNull($card->target_type);
        $this->assertSame('https://93.184.216.34/eid', $card->target_url);
        $this->assertSame(150, $card->rate_per_1000);
        $this->assertNotNull($card->image_path);
        $this->assertStringStartsWith('ads/promo/', $card->image_path);
        Storage::disk('public')->assertExists($card->image_path);

        $link = $this->promotions->create($user, $this->data(['kind' => 'link', 'title' => 'My site', 'body' => 'Look', 'url' => 'https://93.184.216.34/', 'coins' => 200]));
        $this->assertSame(200, $link->rate_per_1000);
        $this->assertSame(1000, $link->view_budget);
        $this->assertSame(['chat_list'], $link->placements);
    }

    public function test_card_and_link_urls_must_be_public_web_addresses(): void
    {
        $user = $this->user();

        foreach (['ftp://example.com/x', 'not a url', 'http://127.0.0.1/admin', 'http://10.0.0.5/'] as $url) {
            try {
                $this->promotions->create($user, $this->data(['kind' => 'card', 'title' => 'x', 'url' => $url]));
                $this->fail("Accepted {$url}");
            } catch (PromotionException $e) {
                $this->assertSame('url', $e->errorCode);
            }
        }

        AppSetting::put(['promo_blocked_hosts' => "spam.example\nbad.test"]);
        $this->mock(HostResolver::class, fn ($m) => $m->shouldReceive('resolve')->andReturn(['93.184.216.34']));
        $this->expectException(PromotionException::class);
        app(PromotionService::class)->create($user, $this->data(['kind' => 'link', 'title' => 'x', 'body' => 'y', 'url' => 'https://shop.spam.example/offer']));
    }

    public function test_only_internal_kinds_can_be_auto_approved(): void
    {
        AppSetting::put(['promo_auto_approve' => true]);
        $user = $this->user();
        $status = $this->makeStatus($user);

        $auto = $this->promotions->create($user, $this->data(['target_id' => $status->id]));
        $this->assertSame('active', $auto->status);
        $this->assertSame('approved', $auto->review_status);
        $this->assertNotNull($auto->starts_at);

        $card = $this->promotions->create($user, $this->data(['kind' => 'card', 'title' => 'x', 'url' => 'https://93.184.216.34/x']));
        $this->assertSame('pending', $card->status);
        $this->assertSame('pending', $card->review_status);
    }

    public function test_there_is_a_limit_on_running_promotions(): void
    {
        AppSetting::put(['promo_max_active' => 2]);
        $user = $this->user(coins: 5000);
        $this->promotions->create($user, $this->data(['kind' => 'card', 'title' => 'a', 'url' => 'https://93.184.216.34/a']));
        $this->promotions->create($user, $this->data(['kind' => 'card', 'title' => 'b', 'url' => 'https://93.184.216.34/b']));

        $this->actingAs($user)->postJson(route('promotions.store'), $this->data(['kind' => 'card', 'title' => 'c', 'url' => 'https://93.184.216.34/c']))
            ->assertStatus(422)->assertJsonPath('code', 'max_active');
    }

    public function test_you_can_only_promote_what_is_yours(): void
    {
        $user = $this->user();
        $other = $this->user();
        $theirStatus = $this->makeStatus($other);
        $theirChannel = $this->channel($other);

        $this->actingAs($user)->postJson(route('promotions.store'), $this->data(['target_id' => $theirStatus->id]))
            ->assertStatus(422)->assertJsonPath('code', 'target');
        $this->actingAs($user)->postJson(route('promotions.store'), $this->data(['kind' => 'channel', 'target_id' => $theirChannel->id]))
            ->assertStatus(422)->assertJsonPath('code', 'target');
        $this->actingAs($user)->postJson(route('promotions.store'), $this->data(['kind' => 'business']))
            ->assertStatus(422)->assertJsonPath('code', 'target');

        $this->assertSame(0, AdCampaign::query()->promotions()->count());
        $this->assertSame(1000, $this->balance($user));
    }

    public function test_a_target_that_is_already_promoted_is_refused(): void
    {
        $user = $this->user();
        $status = $this->makeStatus($user);
        $this->promotions->create($user, $this->data(['target_id' => $status->id]));

        $this->actingAs($user)->postJson(route('promotions.store'), $this->data(['target_id' => $status->id]))
            ->assertStatus(422)->assertJsonPath('code', 'already_promoted');
        $this->assertTrue(collect($this->promotions->targets($user))->firstWhere('kind', 'status')['already_promoted']);
    }

    public function test_a_frozen_wallet_cannot_promote(): void
    {
        $user = $this->user();
        $status = $this->makeStatus($user);
        $this->coins->freeze(User::factory()->admin()->create(), $user, true);

        $this->actingAs($user)->postJson(route('promotions.store'), $this->data(['target_id' => $status->id]))
            ->assertStatus(422)->assertJsonPath('code', 'wallet_frozen');
        $this->assertSame(0, AdCampaign::query()->promotions()->count());

        $this->expectException(WalletFrozenException::class);
        $this->promotions->create($user, $this->data(['target_id' => $status->id]));
    }

    public function test_promotions_are_off_when_the_switch_is(): void
    {
        $user = $this->user();
        AppSetting::put(['promote_enabled' => false]);
        $this->actingAs($user)->getJson(route('promotions.index'))->assertNotFound();

        AppSetting::put(['paid_enabled' => false, 'promote_enabled' => true]);
        $this->actingAs($user)->getJson(route('promotions.index'))->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* Review and refunds */
    /* ------------------------------------------------------------------ */

    public function test_approve_puts_it_live_and_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->user();
        $promo = $this->promotions->create($user, $this->data(['target_id' => $this->makeStatus($user)->id]));

        $promo = $this->promotions->approve($admin, $promo);

        $this->assertSame('active', $promo->status);
        $this->assertSame('approved', $promo->review_status);
        $this->assertSame($admin->id, $promo->reviewed_by);
        $this->assertNotNull($promo->starts_at);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'promotion.approved', 'admin_id' => $admin->id, 'target_id' => $promo->id]);

        // Only once.
        $this->expectException(PromotionException::class);
        $this->promotions->approve($admin, $promo);
    }

    public function test_reject_refunds_everything_once(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->user();
        $promo = $this->promotions->create($user, $this->data(['target_id' => $this->makeStatus($user)->id, 'coins' => 300]));
        $this->assertSame(700, $this->balance($user));

        $promo = $this->promotions->reject($admin, $promo, 'Misleading text');

        $this->assertSame('rejected', $promo->status);
        $this->assertSame('rejected', $promo->stop_reason);
        $this->assertSame('Misleading text', $promo->review_note);
        $this->assertSame(300, $promo->coins_refunded);
        $this->assertNotNull($promo->refunded_at);
        $this->assertSame(1000, $this->balance($user));
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'promotion.rejected', 'target_id' => $promo->id]);

        // A second reject is refused, and a direct second refund is a no-op: one ledger row.
        try {
            $this->promotions->reject($admin, $promo, 'again');
            $this->fail('Rejected twice');
        } catch (PromotionException) {
        }
        $this->assertNull($this->promotions->refund($promo->fresh(), full: true));
        $this->assertSame(1, CoinTransaction::query()->where('idempotency_key', "promo:{$promo->id}:refund")->count());
        $this->assertSame(1000, $this->balance($user));
    }

    public function test_stop_refunds_the_unused_share_with_the_ceil_rule_once(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->user();
        $promo = $this->promotions->create($user, $this->data(['target_id' => $this->makeStatus($user)->id, 'coins' => 100]));
        $this->promotions->approve($admin, $promo);
        // 15 views at 100 per 1,000 = 1.5 coins used → ceil → 2 coins; 98 come back.
        AdCampaign::query()->whereKey($promo->id)->update(['impressions' => 15]);

        $promo = $this->promotions->stop($promo->fresh(), $user, 'user');

        $this->assertSame('stopped', $promo->status);
        $this->assertSame('user', $promo->stop_reason);
        $this->assertSame(98, $promo->coins_refunded);
        $this->assertSame(998, $this->balance($user));
        $this->assertNull($this->promotions->refund($promo->fresh(), full: false));
        $this->assertSame(998, $this->balance($user));
        // The owner stopping it is not an admin action.
        $this->assertDatabaseMissing(AdminAuditLog::class, ['action' => 'promotion.stopped']);

        // Stopping through the app, owner only.
        $other = $this->promotions->create($user, $this->data(['kind' => 'card', 'title' => 'x', 'url' => 'https://93.184.216.34/x', 'coins' => 100]));
        $this->actingAs($this->user())->postJson(route('promotions.stop', $other))->assertNotFound();
        $this->actingAs($user)->postJson(route('promotions.stop', $other))->assertOk()
            ->assertJsonPath('promotion.status', 'stopped')
            ->assertJsonPath('promotion.coins_refunded', 100)   // pending = withdrawal = everything
            ->assertJsonPath('balance', 998);
    }

    public function test_a_finished_promotion_refunds_nothing(): void
    {
        $user = $this->user();
        $promo = $this->promotions->create($user, $this->data(['target_id' => $this->makeStatus($user)->id, 'coins' => 100]));
        AdCampaign::query()->whereKey($promo->id)->update(['status' => 'completed', 'impressions' => 1000, 'completed_at' => now(), 'stop_reason' => 'budget']);

        $this->assertNull($this->promotions->refund($promo->fresh(), full: false));
        $this->assertSame(0, $promo->fresh()->coins_refunded);
        $this->assertSame(900, $this->balance($user));
    }

    public function test_an_expired_status_stops_its_promotion_and_refunds(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->user();
        $status = $this->makeStatus($user);
        $promo = $this->promotions->approve($admin, $this->promotions->create($user, $this->data(['target_id' => $status->id, 'coins' => 100])));
        AdCampaign::query()->whereKey($promo->id)->update(['impressions' => 10]);

        $this->travel(25)->hours();
        $this->artisan('chat:expire-statuses')->assertSuccessful();

        $promo = $promo->fresh();
        $this->assertSame('stopped', $promo->status);
        $this->assertSame('target_gone', $promo->stop_reason);
        $this->assertSame(99, $promo->coins_refunded);
        $this->assertSame(999, $this->balance($user));
        $this->assertDatabaseMissing('statuses', ['id' => $status->id]);
    }

    public function test_deleting_the_channel_community_or_business_stops_the_promotion(): void
    {
        $user = $this->user(coins: 5000);
        $channel = $this->channel($user);
        $community = $this->community($user);
        BusinessProfile::query()->create(['user_id' => $user->id, 'category' => 'shop']);
        $chan = $this->promotions->create($user, $this->data(['kind' => 'channel', 'target_id' => $channel->id]));
        $comm = $this->promotions->create($user, $this->data(['kind' => 'community', 'target_id' => $community->id]));
        $biz = $this->promotions->create($user, $this->data(['kind' => 'business']));
        $this->assertSame(4700, $this->balance($user));

        app(ChannelService::class)->delete($channel, $user);
        $this->assertSame('stopped', $chan->fresh()->status);
        $this->assertSame('target_gone', $chan->fresh()->stop_reason);

        app(CommunityService::class)->delete($community, $user);
        $this->assertSame('stopped', $comm->fresh()->status);

        $this->actingAs($user)->delete(route('business.destroy'))->assertRedirect();
        $this->assertSame('stopped', $biz->fresh()->status);

        // Everything was pending, so everything came back.
        $this->assertSame(5000, $this->balance($user));
    }

    public function test_the_sweep_catches_a_target_that_quietly_disappeared(): void
    {
        $user = $this->user();
        $status = $this->makeStatus($user);
        $promo = $this->promotions->create($user, $this->data(['target_id' => $status->id]));
        Status::query()->whereKey($status->id)->delete(); // no service, no hook

        $this->assertSame(1, $this->promotions->sweepTargets());
        $this->assertSame('stopped', $promo->fresh()->status);
        $this->assertSame(0, $this->promotions->sweepTargets());
    }

    public function test_account_deletion_stops_every_promotion(): void
    {
        $user = $this->user(coins: 5000);
        $a = $this->promotions->create($user, $this->data(['target_id' => $this->makeStatus($user)->id]));
        $b = $this->promotions->create($user, $this->data(['kind' => 'card', 'title' => 'x', 'url' => 'https://93.184.216.34/x']));

        $this->assertSame(2, $this->promotions->stopAllFor($user, 'owner_deleted'));
        $this->assertSame('owner_deleted', $a->fresh()->stop_reason);
        $this->assertSame('owner_deleted', $b->fresh()->stop_reason);
    }

    /* ------------------------------------------------------------------ */
    /* Stats */
    /* ------------------------------------------------------------------ */

    public function test_the_stats_are_for_the_owner_only(): void
    {
        $user = $this->user();
        $promo = $this->promotions->create($user, $this->data(['target_id' => $this->makeStatus($user)->id, 'coins' => 200]));
        DB::table('ad_stats')->insert(['campaign_id' => $promo->id, 'day' => today()->toDateString(), 'impressions' => 40, 'clicks' => 3]);
        DB::table('ad_placement_stats')->insert(['campaign_id' => $promo->id, 'placement' => 'status_list', 'impressions' => 40, 'clicks' => 3]);
        AdCampaign::query()->whereKey($promo->id)->update(['impressions' => 40, 'clicks' => 3]);

        $this->actingAs($this->user())->getJson(route('promotions.show', $promo))->assertNotFound();

        $this->actingAs($user)->getJson(route('promotions.show', $promo))->assertOk()
            ->assertJsonPath('views', 40)
            ->assertJsonPath('taps', 3)
            ->assertJsonPath('ctr', 7.5)
            ->assertJsonPath('budget', 2000)
            ->assertJsonPath('remaining', 1960)
            ->assertJsonPath('spent', 200)
            ->assertJsonPath('status', 'pending')
            ->assertJsonCount(30, 'days')
            ->assertJsonPath('days.29.views', 40)
            ->assertJsonPath('placements.0.placement', 'status_list')
            ->assertJsonPath('placements.0.label', 'Status updates')
            ->assertJsonMissingPath('viewers');

        $this->actingAs($user)->getJson(route('promotions.index'))->assertOk()
            ->assertJsonPath('promotions.0.id', $promo->id)
            ->assertJsonPath('promotions.0.status_label', 'Under review')
            ->assertJsonPath('promotions.0.can_stop', true);
    }
}
