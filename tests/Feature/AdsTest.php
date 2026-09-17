<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdProfile;
use App\Models\AdView;
use App\Models\AppSetting;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Services\AdService;
use App\Services\AdTargetingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ads (Y1): consent, the targeting profile, serving a house ad and recording views/clicks.
 */
class AdsTest extends TestCase
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
            'name' => 'Test', 'status' => 'active', 'title' => 'Buy now', 'cta_label' => 'Learn more',
            'target_url' => 'https://example.com/x', 'per_user_daily_cap' => 3, 'weight' => 1,
        ], $attributes));
    }

    public function test_consent_builds_a_profile_and_opting_out_deletes_it(): void
    {
        $user = $this->user();
        app(AdTargetingService::class)->setConsent($user, true, ['timezone' => 'Asia/Karachi', 'locale' => 'ur-PK', 'platform' => 'android', 'os_version' => '14']);

        $profile = AdProfile::query()->find($user->id);
        $this->assertNotNull($profile);
        $this->assertSame('PK', $profile->country);
        $this->assertSame('Karachi', $profile->region);
        $this->assertSame('android', $profile->platform);
        $this->assertContains('new', $profile->interests);
        $this->assertTrue($user->fresh()->ads_personalised);

        app(AdTargetingService::class)->setConsent($user->fresh(), false);
        $this->assertNull(AdProfile::query()->find($user->id));
        $this->assertFalse($user->fresh()->ads_personalised);
    }

    public function test_segments_come_from_what_the_user_does(): void
    {
        $user = $this->user(['created_at' => now()->subYear()]);
        BusinessProfile::query()->create(['user_id' => $user->id, 'category' => 'shop']);

        $segments = app(AdTargetingService::class)->segments($user);

        $this->assertContains('business', $segments);
        $this->assertNotContains('new', $segments); // joined a year ago
    }

    public function test_coarse_location_is_rounded_and_only_kept_with_permission(): void
    {
        $user = $this->user();
        $targeting = app(AdTargetingService::class);
        $targeting->setConsent($user, true);

        $targeting->rebuild($user->fresh(), ['location_allowed' => true, 'lat' => 24.860731, 'lng' => 67.009912]);
        $this->assertSame('24.86,67.01', AdProfile::query()->find($user->id)->coarse_location);

        $targeting->rebuild($user->fresh(), ['location_allowed' => false]);
        $this->assertNull(AdProfile::query()->find($user->id)->coarse_location);
    }

    public function test_the_master_switch_and_frequency_gate_serving(): void
    {
        $this->campaign();
        $this->assertNull(app(AdService::class)->pickForUser($this->user()));

        AppSetting::put(['ads_enabled' => true]);
        $this->assertNotNull(app(AdService::class)->pickForUser($this->user()));
    }

    public function test_country_targets_everyone_but_interests_need_consent(): void
    {
        AppSetting::put(['ads_enabled' => true]);
        $ads = app(AdService::class);

        // Country from the user's own number reaches even people who did not opt in.
        $pk = $this->campaign(['countries' => ['PK']]);
        $this->assertSame($pk->id, $ads->pickForUser($this->user())->id);
        $this->assertNull($ads->pickForUser($this->user(['phone' => '+14155551234']))); // US number

        $pk->update(['countries' => null, 'interests' => ['business']]);
        // A segment target does not reach a non-consenting user…
        $this->assertNull($ads->pickForUser($this->user()));
        // …but reaches one who opted in and is in that segment.
        $business = $this->user();
        app(AdTargetingService::class)->setConsent($business, true);
        BusinessProfile::query()->create(['user_id' => $business->id, 'category' => 'shop']);
        app(AdTargetingService::class)->rebuild($business->fresh());
        $this->assertSame($pk->id, $ads->pickForUser($business->fresh())->id);
    }

    public function test_age_and_gender_targeting(): void
    {
        AppSetting::put(['ads_enabled' => true]);
        $ads = app(AdService::class);
        $campaign = $this->campaign(['min_age' => 25, 'max_age' => 35, 'gender' => 'female']);

        $user = $this->user();
        app(AdTargetingService::class)->setConsent($user, true);
        app(AdTargetingService::class)->rebuild($user->fresh(), ['gender' => 'female', 'birth_year' => (int) date('Y') - 30]);
        $this->assertSame($campaign->id, $ads->pickForUser($user->fresh())->id);

        app(AdTargetingService::class)->rebuild($user->fresh(), ['gender' => 'male']);
        $this->assertNull($ads->pickForUser($user->fresh()));
    }

    public function test_recording_a_view_caps_frequency_and_counts_stats(): void
    {
        AppSetting::put(['ads_enabled' => true]);
        $campaign = $this->campaign(['per_user_daily_cap' => 2]);
        $user = $this->user();
        $ads = app(AdService::class);

        $ads->recordImpression($campaign, $user);
        $ads->recordImpression($campaign, $user);
        $ads->recordImpression($campaign, $user); // over the cap: ignored

        $this->assertSame(2, $campaign->fresh()->impressions);
        $this->assertSame(2, (int) AdView::query()->where('campaign_id', $campaign->id)->where('user_id', $user->id)->value('views'));
        $this->assertDatabaseHas('ad_stats', ['campaign_id' => $campaign->id, 'impressions' => 2]);

        // A capped ad is not served again the same day.
        $this->assertNull($ads->pickForUser($user->fresh()));

        // A clicked ad is not shown again and is counted.
        $second = $this->campaign();
        $ads->recordClick($second, $user);
        $this->assertSame(1, $second->fresh()->clicks);
        $this->assertTrue((bool) AdView::query()->where('campaign_id', $second->id)->value('clicked'));
    }

    public function test_the_serving_endpoints_need_a_login_and_record_a_view(): void
    {
        AppSetting::put(['ads_enabled' => true]);
        $campaign = $this->campaign(['sponsor' => 'Shop']);

        $this->getJson(route('ads.next'))->assertUnauthorized();

        $user = $this->user();
        $this->actingAs($user)->getJson(route('ads.next'))->assertOk()
            ->assertJsonPath('ad.title', 'Buy now')
            ->assertJsonPath('ad.sponsor', 'Shop')
            ->assertJsonPath('ad.click', route('ads.click', $campaign))
            ->assertJsonMissingPath('ad.countries');
        $this->assertSame(1, $campaign->fresh()->impressions);

        // Clicking records and sends the user to the advertiser.
        $this->actingAs($user)->get(route('ads.click', $campaign))->assertRedirect('https://example.com/x');
        $this->assertSame(1, $campaign->fresh()->clicks);
    }

    public function test_consent_and_ad_data_endpoints(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson(route('ads.consent'), ['personalised' => true, 'timezone' => 'Asia/Karachi'])
            ->assertOk()->assertJsonPath('personalised', true)->assertJsonPath('data.Country', 'PK');

        // The optional fields need consent first.
        $this->actingAs($user)->patchJson(route('ads.profile'), ['gender' => 'female'])->assertOk()->assertJsonPath('gender', 'female');

        $this->actingAs($user)->getJson(route('ads.data'))->assertOk()->assertJsonPath('data.Gender', 'female');

        // Opt out: the profile is gone and the optional fields are refused.
        $this->actingAs($user->fresh())->postJson(route('ads.consent'), ['personalised' => false])->assertOk();
        $this->assertNull(AdProfile::query()->find($user->id));
        $this->actingAs($user->fresh())->patchJson(route('ads.profile'), ['gender' => 'male'])->assertForbidden();
    }
}
