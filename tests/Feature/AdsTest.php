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
 * Ads (Y1): the profile we build from the app's own data and the device, picking an ad for a
 * placement, and recording views and taps.
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

    public function test_the_profile_is_built_from_the_app_and_the_device(): void
    {
        $user = $this->user();

        app(AdTargetingService::class)->rebuild($user, [
            'timezone' => 'Asia/Karachi', 'locale' => 'ur-PK', 'platform' => 'android',
            'os_version' => '14', 'device_model' => 'Pixel 8',
        ]);

        $profile = AdProfile::query()->find($user->id);
        $this->assertSame('PK', $profile->country);      // from their own number
        $this->assertSame('Karachi', $profile->region);  // from the time zone
        $this->assertSame('Pixel 8', $profile->device_model);
        $this->assertContains('new', $profile->segments);
        $this->assertNull($profile->coarse_location);    // no location permission yet
    }

    public function test_segments_come_from_what_the_user_does(): void
    {
        $user = $this->user(['created_at' => now()->subYear()]);
        BusinessProfile::query()->create(['user_id' => $user->id, 'category' => 'shop']);

        $segments = app(AdTargetingService::class)->segments($user);

        $this->assertContains('business', $segments);
        $this->assertNotContains('new', $segments); // joined a year ago
    }

    public function test_the_area_is_rounded_and_only_kept_while_the_permission_is_granted(): void
    {
        $user = $this->user();
        $targeting = app(AdTargetingService::class);

        $targeting->rebuild($user, ['location_allowed' => true, 'lat' => 24.860731, 'lng' => 67.009912]);
        $this->assertSame('24.86,67.01', AdProfile::query()->find($user->id)->coarse_location);

        $targeting->rebuild($user, ['location_allowed' => false]);
        $profile = AdProfile::query()->find($user->id);
        $this->assertNull($profile->coarse_location);
        $this->assertNull($profile->location_at);
    }

    public function test_the_ip_is_recorded_and_the_country_is_read_from_the_host_header(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->withHeaders(['CF-IPCountry' => 'ae'])
            ->postJson(route('ads.open'), ['timezone' => 'Asia/Dubai'])
            ->assertOk();

        $profile = AdProfile::query()->find($user->id);
        $this->assertSame('AE', $profile->ip_country);
        $this->assertNotNull($profile->ip);
        $this->assertSame(1, $profile->opens);
        // The address is never handed back to the app.
        $this->assertArrayNotHasKey('ip', $profile->toArray());
    }

    public function test_the_master_switch_gates_serving(): void
    {
        $this->campaign();
        $this->assertNull(app(AdService::class)->pickForUser($this->user()));

        AppSetting::put(['ads_enabled' => true]);
        $this->assertNotNull(app(AdService::class)->pickForUser($this->user()));
    }

    public function test_an_ad_only_runs_in_the_placements_it_is_booked_for(): void
    {
        AppSetting::put(['ads_enabled' => true]);
        $ads = app(AdService::class);
        $campaign = $this->campaign(['placements' => ['calls']]);

        $this->assertNull($ads->pickForUser($this->user(), 'chat_list'));
        $this->assertSame($campaign->id, $ads->pickForUser($this->user(), 'calls')->id);

        // No placement chosen means "wherever ads are switched on".
        $campaign->update(['placements' => null]);
        $this->assertSame($campaign->id, $ads->pickForUser($this->user(), 'chat_list')->id);
    }

    public function test_a_placement_switched_off_in_the_admin_shows_nothing(): void
    {
        AppSetting::put(['ads_enabled' => true, 'ad_placements' => ['chat_list']]);
        $this->campaign(['placements' => ['calls']]);

        $this->assertNull(app(AdService::class)->pickForUser($this->user(), 'calls'));
        $this->getJson(route('ads.next', ['placement' => 'calls']))->assertUnauthorized();
    }

    public function test_targeting_by_country_segment_age_and_gender(): void
    {
        AppSetting::put(['ads_enabled' => true]);
        $ads = app(AdService::class);
        $campaign = $this->campaign(['countries' => ['PK']]);

        $this->assertNotNull($ads->pickForUser($this->user()));
        $this->assertNull($ads->pickForUser($this->user(['phone' => '+14155551234']))); // US number

        // Segments come from the profile.
        $campaign->update(['countries' => null, 'segments' => ['business']]);
        $this->assertNull($ads->pickForUser($this->user()));

        $business = $this->user();
        BusinessProfile::query()->create(['user_id' => $business->id, 'category' => 'shop']);
        app(AdTargetingService::class)->rebuild($business);
        $this->assertSame($campaign->id, $ads->pickForUser($business)->id);

        // Age and gender come from the person's own profile.
        $campaign->update(['segments' => null, 'min_age' => 25, 'max_age' => 35, 'gender' => 'female']);
        $this->assertNull($ads->pickForUser($this->user())); // nothing set
        $match = $this->user(['gender' => 'female', 'birth_date' => now()->subYears(30)->toDateString()]);
        $this->assertSame($campaign->id, $ads->pickForUser($match)->id);
        $tooOld = $this->user(['gender' => 'female', 'birth_date' => now()->subYears(50)->toDateString()]);
        $this->assertNull($ads->pickForUser($tooOld));
    }

    public function test_the_daily_cap_counts_across_placements(): void
    {
        AppSetting::put(['ads_enabled' => true]);
        $campaign = $this->campaign(['per_user_daily_cap' => 2]);
        $user = $this->user();
        $ads = app(AdService::class);

        $ads->recordImpression($campaign, $user, 'chat_list');
        $ads->recordImpression($campaign, $user, 'calls');
        $ads->recordImpression($campaign, $user, 'chat_list'); // over the cap: ignored

        $this->assertSame(2, $campaign->fresh()->impressions);
        $this->assertNull($ads->pickForUser($user, 'chat_list'));
        $this->assertNull($ads->pickForUser($user, 'calls'));

        // Views and taps are counted per placement, so the admin sees where it works.
        $this->assertDatabaseHas('ad_placement_stats', ['campaign_id' => $campaign->id, 'placement' => 'chat_list', 'impressions' => 1]);
        $this->assertDatabaseHas('ad_placement_stats', ['campaign_id' => $campaign->id, 'placement' => 'calls', 'impressions' => 1]);
    }

    public function test_a_tapped_ad_is_not_shown_again_today(): void
    {
        AppSetting::put(['ads_enabled' => true]);
        $campaign = $this->campaign();
        $user = $this->user();
        $ads = app(AdService::class);

        $ads->recordClick($campaign, $user, 'chat_list');

        $this->assertSame(1, $campaign->fresh()->clicks);
        $this->assertTrue((bool) AdView::query()->where('campaign_id', $campaign->id)->value('clicked'));
        $this->assertNull($ads->pickForUser($user, 'calls'));
    }

    public function test_serving_needs_a_login_records_a_view_and_forwards_a_tap(): void
    {
        AppSetting::put(['ads_enabled' => true]);
        $campaign = $this->campaign(['sponsor' => 'Shop']);

        $this->getJson(route('ads.next'))->assertUnauthorized();

        $user = $this->user();
        $this->actingAs($user)->getJson(route('ads.next', ['placement' => 'status_list']))->assertOk()
            ->assertJsonPath('ad.title', 'Buy now')
            ->assertJsonPath('ad.sponsor', 'Shop')
            ->assertJsonPath('ad.placement', 'status_list')
            ->assertJsonPath('ad.format', 'row')
            ->assertJsonMissingPath('ad.countries');
        $this->assertDatabaseHas('ad_placement_stats', ['campaign_id' => $campaign->id, 'placement' => 'status_list', 'impressions' => 1]);

        $this->actingAs($user)->get(route('ads.click', ['campaign' => $campaign, 'placement' => 'status_list']))
            ->assertRedirect('https://example.com/x');
        $this->assertSame(1, $campaign->fresh()->clicks);

        // An unknown placement is not a thing you can book or report against.
        $this->actingAs($user)->getJson(route('ads.next', ['placement' => 'nowhere']))->assertNotFound();
    }

    public function test_the_ad_data_panel_shows_the_profile_and_the_profile_details(): void
    {
        $user = $this->user(['gender' => 'female', 'birth_date' => now()->subYears(28)->toDateString()]);

        $this->actingAs($user)->postJson(route('ads.open'), ['timezone' => 'Asia/Karachi', 'platform' => 'web'])->assertOk();

        $this->actingAs($user)->getJson(route('ads.data'))->assertOk()
            ->assertJsonPath('data.Country', 'PK')
            ->assertJsonPath('data.Gender', 'female')
            ->assertJsonPath('data.Age', 28)
            ->assertJsonPath('location_allowed', false);
    }

    public function test_gender_and_date_of_birth_are_saved_from_the_profile_page(): void
    {
        $user = $this->user();

        $this->actingAs($user)->from(route('profile.edit'))->put(route('profile.update'), [
            'name' => $user->name, 'username' => $user->username, 'email' => $user->email,
            'gender' => 'male', 'birth_date' => now()->subYears(20)->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('male', $user->fresh()->gender);
        $this->assertSame(20, $user->fresh()->age());

        // Too young to be a real date of birth.
        $this->actingAs($user)->from(route('profile.edit'))->put(route('profile.update'), [
            'name' => $user->name, 'username' => $user->username, 'email' => $user->email,
            'birth_date' => now()->subYears(5)->toDateString(),
        ])->assertSessionHasErrors('birth_date', errorBag: 'profile');
    }
}
