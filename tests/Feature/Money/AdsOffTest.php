<?php

namespace Tests\Feature\Money;

use App\Models\AdCampaign;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Models\User;
use App\Services\AdService;
use App\Services\PlanService;
use App\View\Composers\ChatConfigComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A plan with "no ads" (Y2): the person gets neither house ads nor promotions, and the chat page
 * never starts the ads code for them.
 */
class AdsOffTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['phone' => '+92300'.str_pad((string) ++self::$seq, 7, '0', STR_PAD_LEFT)], $attributes));
    }

    private function campaign(array $attributes = []): AdCampaign
    {
        return AdCampaign::query()->create(array_merge([
            'name' => 'House', 'status' => 'active', 'title' => 'Buy now', 'cta_label' => 'Learn more',
            'target_url' => 'https://example.com/x', 'per_user_daily_cap' => 3, 'weight' => 1,
        ], $attributes));
    }

    private function plan(bool $adsOff): Plan
    {
        return Plan::query()->create([
            'name' => $adsOff ? 'Pro' : 'Lite', 'slug' => ($adsOff ? 'pro' : 'lite').'-'.++self::$seq, 'period' => 'month', 'price_minor' => 100, 'currency' => 'PKR',
            'ads_off' => $adsOff, 'verified_badge' => false, 'monthly_coins' => 0, 'is_active' => true,
        ]);
    }

    public function test_an_ads_off_subscriber_gets_no_house_ads_and_no_promotions(): void
    {
        AppSetting::put(['ads_enabled' => true, 'paid_enabled' => true]);
        $owner = $this->user();
        $this->campaign();
        $this->campaign([
            'name' => 'Promo', 'owner_id' => $owner->id, 'kind' => 'link', 'review_status' => 'approved',
            'coins_spent' => 100, 'rate_per_1000' => 100, 'view_budget' => 1000, 'placements' => ['chat_list'],
        ]);

        $free = $this->user();
        $lite = $this->user();
        $pro = $this->user();
        app(PlanService::class)->activate($lite, $this->plan(adsOff: false), 'manual');
        app(PlanService::class)->activate($pro, $this->plan(adsOff: true), 'manual');

        $ads = app(AdService::class);
        $this->assertNotNull($ads->pickForUser($free, 'chat_list'));
        $this->assertNotNull($ads->pickForUser($lite, 'chat_list'));
        $this->assertNull($ads->pickForUser($pro, 'chat_list'));
        $this->assertNull($ads->pickForUser($pro, 'calls'));

        // The plan ends ⇒ ads come back.
        $this->travel(32)->days();
        $this->artisan('chat:expire-plans')->assertSuccessful();
        $this->assertNotNull(app(AdService::class)->pickForUser($pro->fresh(), 'chat_list'));
    }

    public function test_the_chat_page_config_switches_ads_off_for_the_subscriber(): void
    {
        AppSetting::put(['ads_enabled' => true, 'paid_enabled' => true]);
        $free = $this->user();
        $pro = $this->user();
        app(PlanService::class)->activate($pro, $this->plan(adsOff: true), 'manual');

        $this->assertTrue($this->chatConfigFor($free)['ads']['enabled']);
        $this->assertFalse($this->chatConfigFor($pro)['ads']['enabled']);
    }

    /** The chat page's config as ChatConfigComposer builds it for a real request. */
    private function chatConfigFor(User $user): array
    {
        $response = $this->actingAs($user)->get(route('chat.index'))->assertOk();

        return $response->viewData('chatConfig');
    }
}
