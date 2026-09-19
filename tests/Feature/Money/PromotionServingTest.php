<?php

namespace Tests\Feature\Money;

use App\Models\AdCampaign;
use App\Models\AdView;
use App\Models\AppSetting;
use App\Models\BlockedUser;
use App\Models\BusinessProfile;
use App\Models\Status;
use App\Models\User;
use App\Services\AdService;
use App\Services\BanService;
use App\Services\ChannelService;
use App\Services\CoinService;
use App\Services\CommunityService;
use App\Services\PromotionService;
use App\Services\StatusService;
use App\Support\AdPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Promotions served through the ads pipeline (Y2): who sees them, the view budget that is never
 * overspent, taps that open inside the app, and house ads that behave exactly as before.
 */
class PromotionServingTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private PromotionService $promotions;

    private AdService $ads;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        AppSetting::put(['paid_enabled' => true, 'promote_enabled' => true, 'ads_enabled' => true, 'promo_daily_cap' => 50]);
        $this->promotions = app(PromotionService::class);
        $this->ads = app(AdService::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function user(array $attributes = [], int $coins = 1000): User
    {
        $user = User::factory()->create(array_merge(['phone' => '+92300'.str_pad((string) ++self::$seq, 7, '0', STR_PAD_LEFT)], $attributes));
        if ($coins > 0) {
            app(CoinService::class)->credit($user, $coins, 'purchase', null, 'payment:test:'.$user->id);
        }

        return $user;
    }

    private function makeStatus(User $owner, string $text = 'Hello'): Status
    {
        return app(StatusService::class)->createText($owner, $text, 'teal', 0);
    }

    /** A promotion of `$views` views (rate 1,000 per 1,000 views ⇒ one coin per view). */
    private function promote(User $owner, array $data, int $views = 5, bool $approve = true): AdCampaign
    {
        AppSetting::put(['promo_rate_'.$data['kind'] => 1000, 'promo_min_coins' => 1]);
        $promo = $this->promotions->create($owner, $data + ['coins' => $views, 'client_token' => (string) Str::uuid()]);

        return $approve ? $this->promotions->approve($this->admin, $promo) : $promo;
    }

    private function house(array $attributes = []): AdCampaign
    {
        return AdCampaign::query()->create(array_merge([
            'name' => 'House', 'status' => 'active', 'title' => 'Buy now', 'cta_label' => 'Learn more',
            'target_url' => 'https://example.com/x', 'per_user_daily_cap' => 3, 'weight' => 1,
        ], $attributes));
    }

    /* ------------------------------------------------------------------ */
    /* Picking */
    /* ------------------------------------------------------------------ */

    public function test_only_approved_running_promotions_are_picked_and_never_by_their_owner(): void
    {
        $owner = $this->user();
        $viewer = $this->user();
        $status = $this->makeStatus($owner);

        $pending = $this->promote($owner, ['kind' => 'status', 'target_id' => $status->id], approve: false);
        $this->assertNull($this->ads->pickForUser($viewer, 'status_list'));

        $this->promotions->reject($this->admin, $pending, 'no');
        $this->assertNull($this->ads->pickForUser($viewer, 'status_list'));

        $live = $this->promote($owner, ['kind' => 'status', 'target_id' => $status->id]);
        $this->assertSame($live->id, $this->ads->pickForUser($viewer, 'status_list')->id);
        $this->assertSame($live->id, $this->ads->pickForUser($viewer, 'chat_list')->id);
        $this->assertNull($this->ads->pickForUser($viewer, 'calls'), 'status promotions do not run in Calls');
        $this->assertNull($this->ads->pickForUser($owner, 'status_list'), 'owners never see their own card');

        $this->promotions->stop($live, $owner, 'user');
        $this->assertNull($this->ads->pickForUser($viewer, 'status_list'));
    }

    public function test_impressions_stop_exactly_at_the_budget_and_the_nth_view_completes_it(): void
    {
        $owner = $this->user();
        $promo = $this->promote($owner, ['kind' => 'status', 'target_id' => $this->makeStatus($owner)->id], views: 5);
        $this->assertSame(5, $promo->view_budget);

        // Six people each see it once — well under the daily cap for each of them.
        $viewers = [];
        for ($i = 0; $i < 6; $i++) {
            $viewers[] = $this->user(coins: 0);
        }
        foreach (array_slice($viewers, 0, 4) as $viewer) {
            $this->ads->recordImpression($promo, $viewer, 'status_list');
            $this->assertSame('active', $promo->fresh()->status);
        }

        $this->ads->recordImpression($promo, $viewers[4], 'status_list'); // the 5th: budget used
        $done = $promo->fresh();
        $this->assertSame(5, $done->impressions);
        $this->assertSame('completed', $done->status);
        $this->assertSame('budget', $done->stop_reason);
        $this->assertNotNull($done->completed_at);
        $this->assertNull($this->ads->pickForUser($viewers[5], 'status_list'));

        // The 6th (a pick that raced the last count) lands nowhere: no impression, no stats, no view row.
        $this->ads->recordImpression($promo, $viewers[5], 'status_list');
        $this->assertSame(5, $promo->fresh()->impressions);
        $this->assertDatabaseHas('ad_stats', ['campaign_id' => $promo->id, 'impressions' => 5]);
        $this->assertDatabaseHas('ad_placement_stats', ['campaign_id' => $promo->id, 'placement' => 'status_list', 'impressions' => 5]);
        $this->assertSame(0, AdView::query()->where('campaign_id', $promo->id)->where('user_id', $viewers[5]->id)->count());
        $this->assertSame(5, (int) AdView::query()->where('campaign_id', $promo->id)->sum('views'));

        // The same person under the daily cap also stops at the budget.
        $again = $this->promote($owner, ['kind' => 'card', 'title' => 'x', 'url' => 'https://93.184.216.34/x'], views: 3);
        $one = $this->user(coins: 0);
        for ($i = 0; $i < 5; $i++) {
            $this->ads->recordImpression($again, $one, 'chat_list');
        }
        $this->assertSame(3, $again->fresh()->impressions);
        $this->assertSame('completed', $again->fresh()->status);
        $this->assertSame(3, (int) AdView::query()->where('campaign_id', $again->id)->value('views'));
        // Finished = no refund.
        $this->assertNull($this->promotions->refund($again->fresh(), full: false));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $owner->id]);
    }

    public function test_a_stale_pick_that_lost_the_last_view_race_counts_nothing(): void
    {
        $owner = $this->user();
        $promo = $this->promote($owner, ['kind' => 'status', 'target_id' => $this->makeStatus($owner)->id], views: 2);
        $a = $this->user(coins: 0);
        $b = $this->user(coins: 0);
        $c = $this->user(coins: 0);

        // Three requests picked the card while it still had views; two land, the third finds none left.
        $pickedA = $promo->fresh();
        $pickedB = $promo->fresh();
        $pickedC = $promo->fresh();
        $this->ads->recordImpression($pickedA, $a);
        $this->ads->recordImpression($pickedB, $b);
        $this->ads->recordImpression($pickedC, $c);

        $this->assertSame(2, $promo->fresh()->impressions);
        $this->assertSame('completed', $promo->fresh()->status);
        $this->assertSame(1, AdCampaign::query()->where('id', $promo->id)->where('status', 'completed')->count());
        $this->assertSame(2, AdView::query()->where('campaign_id', $promo->id)->count());
    }

    public function test_a_promotion_whose_placements_were_switched_off_runs_nowhere_at_all(): void
    {
        $owner = $this->user();
        $viewer = $this->user(coins: 0);
        $promo = $this->promote($owner, ['kind' => 'card', 'title' => 'Eid sale', 'url' => 'https://93.184.216.34/eid'], views: 100);
        $this->assertSame(['chat_list'], $promo->placements);

        // The admin switches the Chats list off. The card was booked for that screen only, so it
        // now has nowhere to run — it must not fall back to "every screen".
        AppSetting::put(['ad_placements' => ['status_list', 'channels', 'calls', 'chat_top']]);
        $this->assertSame([], $promo->fresh()->placementList());
        foreach (AdPlacement::keys() as $placement) {
            $this->assertNull($this->ads->pickForUser($viewer, $placement), "the promotion was served in {$placement}");
        }

        // Same for a row booked with no placement at all (nothing was ticked at the time): for a
        // promotion an empty list is "nowhere", never "everywhere" — a user's card must never end
        // up as the banner inside open chats or between the calls.
        AdCampaign::query()->whereKey($promo->id)->update(['placements' => json_encode([])]);
        $this->assertSame([], $promo->fresh()->placementList());
        foreach (AdPlacement::keys() as $placement) {
            $this->assertNull($this->ads->pickForUser($viewer, $placement), "the promotion was served in {$placement}");
        }

        // A house ad with nothing ticked still means "wherever ads are switched on" (Y1).
        $house = $this->house();
        $this->assertSame(['status_list', 'channels', 'calls', 'chat_top'], $house->placementList());
        $this->assertSame($house->id, $this->ads->pickForUser($viewer, 'calls')?->id);
    }

    public function test_a_banned_owner_stops_being_promoted(): void
    {
        $owner = $this->user();
        $viewer = $this->user(coins: 0);
        $promo = $this->promote($owner, ['kind' => 'status', 'target_id' => $this->makeStatus($owner, 'Cheap phones')->id], views: 100);
        $this->assertSame($promo->id, $this->ads->pickForUser($viewer, 'status_list')?->id);

        // An account that is not active is never served, even before anything stops its rows.
        $owner->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->assertNull($this->ads->pickForUser($viewer, 'status_list'));
        $owner->forceFill(['status' => User::STATUS_ACTIVE])->save();

        app(BanService::class)->ban($owner, $this->admin, 7, 'Spam');

        $stopped = $promo->fresh();
        $this->assertSame('stopped', $stopped->status);
        $this->assertSame('owner_banned', $stopped->stop_reason);
        $this->assertSame(100, $stopped->coins_refunded);
        $this->assertNull($this->ads->pickForUser($viewer, 'status_list'));
        $this->assertNull($this->ads->pickForUser($viewer, 'chat_list'));

        // And the card still on somebody's screen hands out nothing of theirs.
        $this->actingAs($viewer)->postJson(route('ads.tap', $promo), ['placement' => 'status_list'])->assertNotFound();
        $this->actingAs($viewer)->get(route('promotions.go', $promo))->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* Taps */
    /* ------------------------------------------------------------------ */

    public function test_a_tap_on_the_completed_card_still_records(): void
    {
        $owner = $this->user();
        $promo = $this->promote($owner, ['kind' => 'card', 'title' => 'Eid', 'url' => 'https://93.184.216.34/eid'], views: 1);
        $viewer = $this->user(coins: 0);
        $this->ads->recordImpression($promo, $viewer);
        $this->assertSame('completed', $promo->fresh()->status);

        $this->actingAs($viewer)->postJson(route('ads.tap', $promo), ['placement' => 'chat_list'])->assertOk()
            ->assertJsonPath('open', null)
            ->assertJsonPath('url', 'https://93.184.216.34/eid');
        $this->assertSame(1, $promo->fresh()->clicks);

        $this->actingAs($viewer)->get(route('ads.click', ['campaign' => $promo, 'placement' => 'chat_list']))->assertRedirect('https://93.184.216.34/eid');
        $this->assertSame(2, $promo->fresh()->clicks);

        // A rejected one is gone for good.
        $dead = $this->promote($owner, ['kind' => 'card', 'title' => 'x', 'url' => 'https://93.184.216.34/x'], approve: false);
        $this->promotions->reject($this->admin, $dead, 'no');
        $this->actingAs($viewer)->postJson(route('ads.tap', $dead))->assertNotFound();
    }

    public function test_the_tap_answers_with_what_to_open_per_kind(): void
    {
        $owner = $this->user(coins: 5000);
        $viewer = $this->user(coins: 0);
        $status = $this->makeStatus($owner, 'Promoted status');
        $channel = app(ChannelService::class)->create($owner, 'Cricket');
        $community = app(CommunityService::class)->create($owner, 'Block 7', null, null);
        BusinessProfile::query()->create(['user_id' => $owner->id, 'category' => 'shop']);

        $s = $this->promote($owner, ['kind' => 'status', 'target_id' => $status->id]);
        $this->actingAs($viewer)->postJson(route('ads.tap', $s), ['placement' => 'status_list'])->assertOk()
            ->assertJsonPath('open.type', 'status')
            ->assertJsonPath('open.user.id', $owner->id)
            ->assertJsonPath('open.status.id', $status->id)
            ->assertJsonPath('open.status.text', 'Promoted status')
            ->assertJsonMissingPath('open.status.privacy');

        $c = $this->promote($owner, ['kind' => 'channel', 'target_id' => $channel->id]);
        $this->actingAs($viewer)->postJson(route('ads.tap', $c), ['placement' => 'channels'])->assertOk()
            ->assertJsonPath('open.type', 'channel')
            ->assertJsonPath('open.id', $channel->id);

        $m = $this->promote($owner, ['kind' => 'community', 'target_id' => $community->id]);
        $this->actingAs($viewer)->postJson(route('ads.tap', $m))->assertOk()
            ->assertJsonPath('open.type', 'community')
            ->assertJsonPath('open.invite.valid', true)
            ->assertJsonPath('open.invite.token', $community->fresh()->invite_token)
            ->assertJsonPath('open.invite.name', 'Block 7')
            ->assertJsonPath('open.invite.is_member', false);

        $b = $this->promote($owner, ['kind' => 'business']);
        $this->actingAs($viewer)->postJson(route('ads.tap', $b))->assertOk()
            ->assertJsonPath('open.type', 'business')
            ->assertJsonPath('open.user_id', $owner->id);

        $this->assertSame(1, $s->fresh()->clicks);
        $this->assertDatabaseHas('ad_placement_stats', ['campaign_id' => $c->id, 'placement' => 'channels', 'clicks' => 1]);

        // The card is delivered with its promoted flags.
        $this->assertNull($this->ads->pickForUser($viewer, 'calls'));
        $payload = $s->fresh()->payload();
        $this->assertTrue($payload['promoted']);
        $this->assertTrue($payload['internal']);
        $this->assertSame('status', $payload['kind']);
        $this->assertSame($owner->name, $payload['sponsor']);

        // The web fallback link renders the chat page with the same target.
        $response = $this->actingAs($viewer)->get(route('promotions.go', $s))->assertOk();
        $this->assertSame('status', $response->viewData('openTarget')['type']);
        $this->assertSame($status->id, $response->viewData('openTarget')['status']['id']);
        $response->assertSee('"openTarget"', false);

        $this->actingAs($viewer)->get(route('promotions.go', $this->house()))->assertNotFound();
    }

    public function test_a_promoted_status_is_visible_to_people_outside_the_contacts(): void
    {
        $owner = $this->user();
        $stranger = $this->user(coins: 0);
        $status = $this->makeStatus($owner);

        $this->actingAs($stranger)->postJson(route('statuses.view', $status))->assertNotFound();

        $promo = $this->promote($owner, ['kind' => 'status', 'target_id' => $status->id]);
        $this->assertTrue($this->promotions->grantsStatusView($status, $stranger));
        $this->assertFalse($this->promotions->grantsStatusView($status, $owner));
        $this->actingAs($stranger)->postJson(route('statuses.view', $status))->assertOk();
        $this->assertDatabaseHas('status_views', ['status_id' => $status->id, 'user_id' => $stranger->id]);

        // Once stopped, the grant is gone (a finished one keeps it for the last served cards).
        $this->promotions->stop($promo, $owner, 'user');
        $this->assertFalse($this->promotions->grantsStatusView($status, $stranger));
        $this->actingAs($this->user(coins: 0))->postJson(route('statuses.view', $status))->assertNotFound();
    }

    public function test_a_promoted_status_still_obeys_blocks_and_the_owners_privacy_list(): void
    {
        $owner = $this->user();
        $blocked = $this->user(coins: 0);
        $stranger = $this->user(coins: 0);
        $status = $this->makeStatus($owner, 'Secret');
        $promo = $this->promote($owner, ['kind' => 'status', 'target_id' => $status->id], views: 100);
        BlockedUser::query()->create(['user_id' => $owner->id, 'blocked_user_id' => $blocked->id]);

        // A stranger is exactly who the promotion is for.
        $this->actingAs($stranger)->postJson(route('ads.tap', $promo), ['placement' => 'status_list'])->assertOk()
            ->assertJsonPath('open.type', 'status')
            ->assertJsonPath('open.status.text', 'Secret');

        // The blocked person is never shown the card and never gets its contents.
        $this->assertNull($this->ads->pickForUser($blocked, 'status_list'));
        $this->actingAs($blocked)->postJson(route('ads.tap', $promo), ['placement' => 'status_list'])->assertOk()
            ->assertJsonPath('open', null)
            ->assertJsonPath('url', null);
        $this->assertSame(['type' => 'gone'], $this->actingAs($blocked)->get(route('promotions.go', $promo))->assertOk()->viewData('openTarget'));

        // Once the promotion is over the grant is gone, so the words go with it.
        $this->promotions->stop($promo, $owner, 'user');
        $this->actingAs($stranger)->postJson(route('ads.tap', $promo), ['placement' => 'status_list'])->assertOk()
            ->assertJsonPath('open', null)
            ->assertJsonPath('url', null);
    }

    public function test_a_promotion_whose_target_is_gone_neither_loops_nor_falls_back_to_its_own_page(): void
    {
        $owner = $this->user();
        $viewer = $this->user(coins: 0);
        BusinessProfile::query()->create(['user_id' => $owner->id, 'category' => 'shop']);
        $promo = $this->promote($owner, ['kind' => 'business'], views: 100);
        $this->assertSame(route('promotions.go', $promo), $promo->target_url);

        BusinessProfile::query()->where('user_id', $owner->id)->delete();   // no service, no hook

        // The tap answers with nothing rather than with the page the app is already on.
        $this->actingAs($viewer)->postJson(route('ads.tap', $promo))->assertOk()
            ->assertJsonPath('open', null)
            ->assertJsonPath('url', null);

        $target = $this->actingAs($viewer)->get(route('promotions.go', $promo))->assertOk()->viewData('openTarget');
        $this->assertSame(['type' => 'gone'], $target);
    }

    public function test_a_promotion_that_was_never_approved_can_never_send_anybody_anywhere(): void
    {
        $owner = $this->user(coins: 5000);
        $viewer = $this->user(coins: 0);

        // Pending review: the app-domain link must not redirect to the submitted address.
        $pending = $this->promote($owner, ['kind' => 'card', 'title' => 'Win a phone', 'url' => 'https://93.184.216.34/phish'], approve: false);
        $this->actingAs($viewer)->get(route('promotions.go', $pending))->assertNotFound();
        $this->actingAs($viewer)->postJson(route('ads.tap', $pending))->assertNotFound();

        $this->promotions->reject($this->admin, $pending, 'Phishing');
        $this->actingAs($viewer)->get(route('promotions.go', $pending))->assertNotFound();
        $this->actingAs($viewer)->postJson(route('ads.tap', $pending))->assertNotFound();

        // An admin taking a running one down means down, cards already on screen included.
        $live = $this->promote($owner, ['kind' => 'card', 'title' => 'Win a phone', 'url' => 'https://93.184.216.34/phish']);
        $this->promotions->stop($live, $this->admin, 'admin', refund: false);
        $this->actingAs($viewer)->postJson(route('ads.tap', $live))->assertNotFound();
        $this->actingAs($viewer)->get(route('ads.click', ['campaign' => $live]))->assertNotFound();
        $this->actingAs($viewer)->get(route('promotions.go', $live))->assertNotFound();

        // The owner stopping their own card is not a take-down: a tap on it still counts.
        $mine = $this->promote($owner, ['kind' => 'card', 'title' => 'Eid', 'url' => 'https://93.184.216.34/eid']);
        $this->promotions->stop($mine, $owner, 'user');
        $this->actingAs($viewer)->postJson(route('ads.tap', $mine))->assertOk()
            ->assertJsonPath('url', 'https://93.184.216.34/eid');
    }

    /* ------------------------------------------------------------------ */
    /* House ads */
    /* ------------------------------------------------------------------ */

    public function test_house_ads_are_unaffected(): void
    {
        $house = $this->house(['per_user_daily_cap' => 10]);
        $viewer = $this->user(coins: 0);

        for ($i = 0; $i < 7; $i++) {
            $this->ads->recordImpression($house, $viewer);
        }
        $this->assertSame(7, $house->fresh()->impressions);
        $this->assertSame('active', $house->fresh()->status);
        $this->assertNull($house->fresh()->completed_at);
        $this->assertSame($house->id, $this->ads->pickForUser($viewer)->id);

        $this->actingAs($viewer)->postJson(route('ads.tap', $house))->assertOk()
            ->assertJsonPath('open', null)->assertJsonPath('url', 'https://example.com/x');
        $this->assertFalse($house->fresh()->payload()['promoted']);

        // The admin's Ads page lists house ads only; promotions have their own queue.
        $owner = $this->user();
        $promo = $this->promote($owner, ['kind' => 'card', 'title' => 'Promo card', 'url' => 'https://93.184.216.34/x']);
        $this->actingAs($this->admin)->get(route('admin.ads'))->assertOk()
            ->assertSee('House')->assertDontSee('Promo card')->assertSee('1 live');
        $this->actingAs($this->admin)->get(route('admin.promotions', ['tab' => 'active']))->assertOk()->assertSee('Promo card');

        // Ads off for a plan with the benefit: nothing at all (house or promoted).
        AppSetting::put(['ads_enabled' => false]);
        $this->assertNull($this->ads->pickForUser($viewer));
        $this->actingAs($viewer)->postJson(route('ads.tap', $promo))->assertOk(); // a tap on a card already on screen still records
    }
}
