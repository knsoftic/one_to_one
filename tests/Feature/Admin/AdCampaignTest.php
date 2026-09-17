<?php

namespace Tests\Feature\Admin;

use App\Models\AdCampaign;
use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\AdPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin → Ads: creating and managing house ad campaigns, and the ads app settings.
 */
class AdCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->admin()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Eid sale', 'status' => 'active', 'title' => 'Up to 50% off',
            'cta_label' => 'Shop now', 'target_url' => 'https://shop.example.com/eid',
            'per_user_daily_cap' => 3, 'weight' => 2,
        ], $overrides);
    }

    public function test_the_list_shows_campaigns_and_warns_when_ads_are_off(): void
    {
        AdCampaign::query()->create($this->payload(['name' => 'Winter promo']));

        $this->actingAs($this->admin)->get(route('admin.ads'))->assertOk()
            ->assertSee('Winter promo')
            ->assertSee('Ads are switched off');

        // The nav links to it and the dashboard is reachable.
        $this->actingAs($this->admin)->get(route('admin.reports'))->assertSee(route('admin.ads'), false);
    }

    public function test_it_creates_a_campaign_with_targeting_and_an_image(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.ads.store'), $this->payload([
            'sponsor' => 'Liberty', 'body' => 'Free delivery',
            'countries' => ['PK', 'AE'], 'segments' => ['business', 'active'],
            'min_age' => 18, 'max_age' => 45, 'gender' => 'female', 'placements' => ['chat_list', 'calls'],
            'image' => UploadedFile::fake()->image('ad.jpg', 1200, 600),
        ]));

        $ad = AdCampaign::query()->firstWhere('name', 'Eid sale');
        $this->assertNotNull($ad);
        $response->assertRedirect(route('admin.ads.edit', $ad));
        $this->assertSame(['PK', 'AE'], $ad->countries);
        $this->assertSame(['business', 'active'], $ad->segments);
        $this->assertSame(45, $ad->max_age);
        $this->assertSame(['chat_list', 'calls'], $ad->placements);
        $this->assertSame(['chat_list', 'calls'], $ad->placementList());
        Storage::disk('public')->assertExists($ad->image_path);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'ad.created', 'admin_id' => $this->admin->id]);
    }

    public function test_it_checks_the_link_and_the_age_range(): void
    {
        $this->actingAs($this->admin)->post(route('admin.ads.store'), $this->payload(['target_url' => 'not-a-url']))->assertSessionHasErrors('target_url');
        $this->actingAs($this->admin)->post(route('admin.ads.store'), $this->payload(['min_age' => 40, 'max_age' => 20]))->assertSessionHasErrors('max_age');
        $this->actingAs($this->admin)->post(route('admin.ads.store'), $this->payload(['countries' => ['ZZ']]))->assertSessionHasErrors('countries.0');
        $this->actingAs($this->admin)->post(route('admin.ads.store'), $this->payload(['placements' => ['nowhere']]))->assertSessionHasErrors('placements.0');
        $this->assertSame(0, AdCampaign::query()->count());
    }

    public function test_it_updates_and_can_remove_the_image(): void
    {
        $ad = AdCampaign::query()->create($this->payload(['image_path' => UploadedFile::fake()->image('old.jpg')->store('ads', 'public')]));
        $old = $ad->image_path;

        $this->actingAs($this->admin)->put(route('admin.ads.update', $ad), $this->payload(['title' => 'New headline', 'status' => 'paused', 'remove_image' => '1']))
            ->assertRedirect(route('admin.ads.edit', $ad));

        $ad->refresh();
        $this->assertSame('New headline', $ad->title);
        $this->assertSame('paused', $ad->status);
        $this->assertNull($ad->image_path);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_it_deletes_a_campaign_and_its_image(): void
    {
        $ad = AdCampaign::query()->create($this->payload(['image_path' => UploadedFile::fake()->image('x.jpg')->store('ads', 'public')]));

        $this->actingAs($this->admin)->delete(route('admin.ads.destroy', $ad))->assertRedirect(route('admin.ads'));

        $this->assertModelMissing($ad);
        Storage::disk('public')->assertMissing($ad->image_path);
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'ad.deleted']);
    }

    public function test_only_admins_can_manage_ads(): void
    {
        $ad = AdCampaign::query()->create($this->payload());
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.ads'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.ads.store'), $this->payload())->assertForbidden();
        $this->actingAs($user)->delete(route('admin.ads.destroy', $ad))->assertForbidden();
    }

    public function test_ad_settings_save_and_are_checked(): void
    {
        $base = ['registration_open' => '1', 'signup_email' => 'optional', 'sms_driver' => 'log', 'mail_mailer' => 'log'];

        $this->actingAs($this->admin)->put(route('admin.settings.update'), $base + [
            'ads_enabled' => '1', 'ad_frequency' => '8',
            'admob_app_id' => 'ca-app-pub-1234567890123456~1234567890',
            'admob_native_unit' => 'ca-app-pub-1234567890123456/1234567890',
            'admob_test' => '1',
            'adsense_client' => 'ca-pub-1234567890123456', 'adsense_slot' => '1234567890',
            'ads_section' => '1', 'ad_placements' => ['chat_list', 'status_list'],
        ])->assertSessionHasNoErrors();

        $this->assertTrue((bool) AppSetting::get('ads_enabled'));
        $this->assertSame(8, (int) AppSetting::get('ad_frequency'));
        $this->assertSame('ca-app-pub-1234567890123456~1234567890', AppSetting::get('admob_app_id'));
        $this->assertTrue((bool) AppSetting::get('admob_test'));
        $this->assertSame(['chat_list', 'status_list'], AppSetting::get('ad_placements'));
        $this->assertFalse(AdPlacement::isEnabled('calls'));

        // Bad AdMob / AdSense IDs are refused.
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $base + ['admob_app_id' => 'nope'])->assertSessionHasErrors('admob_app_id');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $base + ['adsense_client' => 'pub-1'])->assertSessionHasErrors('adsense_client');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $base + ['ad_frequency' => '2'])->assertSessionHasErrors('ad_frequency');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $base + ['ad_placements' => ['nowhere']])->assertSessionHasErrors('ad_placements.0');
    }
}
