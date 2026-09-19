<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * App settings → Paid features (Y2): the master switch, rates, prices and payment-provider keys.
 */
class PaidSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** The fields the rest of the settings form always posts. */
    private array $base = ['registration_open' => '1', 'signup_email' => 'optional', 'sms_driver' => 'log', 'mail_mailer' => 'log', 'paid_section' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_section_renders_with_every_switch_off_by_default(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()
            ->assertSee('Paid features')
            ->assertSee('Turn on paid features')
            ->assertSee('Google Play Billing')
            ->assertSee(route('webhooks.stripe'), false);

        $this->assertFalse((bool) AppSetting::get('paid_enabled'));
        $this->assertSame('PKR', AppSetting::get('paid_currency'));
    }

    public function test_switches_rates_and_texts_are_saved_and_audited(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->base + [
            'paid_enabled' => '1', 'paid_currency' => 'USD', 'promote_enabled' => '1',
            'promo_rate_status' => '80', 'promo_rate_link' => '400', 'promo_min_coins' => '20', 'promo_max_coins' => '9000',
            'promo_placements' => ['chat_list', 'calls'], 'promo_blocked_hosts' => "spam.example\nbad.example",
            'referral_reward' => '75', 'referral_welcome' => '10', 'badge_coin_price' => '0', 'badge_days' => '0',
            'manual_enabled' => '1', 'manual_jazzcash' => 'JazzCash 0300 1234567 (Ali)', 'manual_expire_hours' => '48',
            'stripe_enabled' => '1', 'play_enabled' => '1', 'play_min_app_code' => '12',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue((bool) AppSetting::get('paid_enabled'));
        $this->assertSame('USD', AppSetting::get('paid_currency'));
        $this->assertSame(80, (int) AppSetting::get('promo_rate_status'));
        $this->assertSame(400, (int) AppSetting::get('promo_rate_link'));
        $this->assertSame(['chat_list', 'calls'], AppSetting::get('promo_placements'));
        $this->assertSame("spam.example\nbad.example", AppSetting::get('promo_blocked_hosts'));
        $this->assertSame(75, (int) AppSetting::get('referral_reward'));
        $this->assertSame(0, (int) AppSetting::get('badge_coin_price'));
        $this->assertSame('JazzCash 0300 1234567 (Ali)', AppSetting::get('manual_jazzcash'));
        $this->assertSame(12, (int) AppSetting::get('play_min_app_code'));
        $this->assertTrue((bool) AppSetting::get('stripe_enabled'));

        $log = AdminAuditLog::query()->where('action', 'paid.settings_updated')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('paid_enabled', $log->description);
        $this->assertStringNotContainsString('0300', $log->description);
    }

    public function test_provider_secrets_are_encrypted_and_never_rendered(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->base + [
            'stripe_publishable_key' => 'pk_test_abc123',
            'stripe_secret_key' => 'sk_test_verysecret',
            'stripe_webhook_secret' => 'whsec_alsosecret',
            'paypal_client_id' => 'client-id-1', 'paypal_secret' => 'paypal-secret', 'paypal_mode' => 'live',
            'play_package_name' => 'com.hunario.chat',
            'play_service_account_json' => json_encode(['type' => 'service_account', 'client_email' => 'x@y', 'private_key' => 'k']),
        ])->assertSessionHasNoErrors();

        $this->assertSame('pk_test_abc123', AppSetting::get('stripe_publishable_key'));
        $this->assertSame('sk_test_verysecret', Crypt::decryptString(AppSetting::get('stripe_secret_key')));
        $this->assertSame('sk_test_verysecret', config('services.stripe.secret'));
        $this->assertSame('live', config('services.paypal.mode'));
        $this->assertStringContainsString('service_account', config('services.play.service_account'));

        $page = $this->actingAs($this->admin)->get(route('admin.settings'));
        $page->assertOk()->assertDontSee('sk_test_verysecret')->assertDontSee('paypal-secret')->assertDontSee('whsec_alsosecret')->assertSee('Saved');

        // The audit line names the keys, never the values.
        $this->assertStringNotContainsString('verysecret', AdminAuditLog::query()->latest('id')->value('description'));
    }

    public function test_bad_values_are_refused(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->base + ['paid_currency' => 'XYZ'])->assertSessionHasErrors('paid_currency');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->base + ['promo_min_coins' => '500', 'promo_max_coins' => '100'])->assertSessionHasErrors('promo_max_coins');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->base + ['promo_placements' => ['nowhere']])->assertSessionHasErrors('promo_placements.0');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->base + ['stripe_secret_key' => 'not-a-key'])->assertSessionHasErrors('stripe_secret_key');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->base + ['play_service_account_json' => '{not json'])->assertSessionHasErrors('play_service_account_json');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->base + ['play_package_name' => 'nodots'])->assertSessionHasErrors('play_package_name');
    }

    public function test_the_paid_section_is_only_saved_when_it_was_submitted(): void
    {
        AppSetting::put(['paid_enabled' => true, 'promo_rate_status' => 123]);

        // A save of another section (no paid_section marker) leaves the paid settings alone.
        $this->actingAs($this->admin)->put(route('admin.settings.update'), array_diff_key($this->base, ['paid_section' => 1]))->assertSessionHasNoErrors();

        $this->assertTrue((bool) AppSetting::get('paid_enabled'));
        $this->assertSame(123, (int) AppSetting::get('promo_rate_status'));
    }

    public function test_the_refund_policy_page_is_public(): void
    {
        $this->get(route('legal', 'refunds'))->assertOk()->assertSee('Refund policy')->assertSee('Google Play');
    }
}
