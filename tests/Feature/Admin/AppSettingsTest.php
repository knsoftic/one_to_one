<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AppConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin panel — settings that used to be typed into .env (SMS, email, GIFs, calls,
 * invite link, sign-up), saved in MySQL with passwords and keys encrypted.
 */
class AppSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
    }

    private function save(array $values): TestResponse
    {
        return $this->actingAs($this->admin)->from('/admin/settings')->put('/admin/settings', $values + ['registration_open' => '1', 'signup_email' => 'optional']);
    }

    public function test_integration_settings_are_saved_encrypted_and_used_by_the_app(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->assertSee('Twilio')->assertSee('SMTP host')->assertSee('Replaces SMS_DRIVER');

        $this->save([
            'sms_driver' => 'twilio',
            'sms_twilio_sid' => 'AC123',
            'sms_twilio_token' => 'super-secret-token',
            'sms_twilio_from' => '+15550001111',
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => '465',
            'mail_encryption' => 'ssl',
            'mail_username' => 'mailer@example.com',
            'mail_password' => 'mail-pass-123',
            'mail_from_address' => 'no-reply@example.com',
            'mail_from_name' => 'One2One',
            'tenor_key' => 'tenor-key-xyz',
            'turn_urls' => 'turn:turn.example.com:3478',
            'turn_secret' => 'coturn-secret',
            'invite_url' => 'https://example.com/app.apk',
        ])->assertRedirect('/admin/settings')->assertSessionHasNoErrors()->assertSessionHas('status', 'Settings saved.');

        // Secrets are encrypted in MySQL and never shown again.
        $raw = DB::table('app_settings')->pluck('value', 'key');
        foreach (['super-secret-token', 'mail-pass-123', 'tenor-key-xyz', 'coturn-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw->implode(' '));
        }
        $this->assertSame('smtp.example.com', AppSetting::get('mail_host'));
        $page = $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->assertSee('AC123')->assertSee('Saved — leave empty to keep it');
        foreach (['super-secret-token', 'mail-pass-123', 'tenor-key-xyz', 'coturn-secret'] as $secret) {
            $page->assertDontSee($secret);
        }

        // The app uses them.
        $this->assertSame('twilio', config('services.sms.driver'));
        $this->assertSame('super-secret-token', config('services.sms.twilio.token'));
        $this->assertSame(['smtp', 'smtp.example.com', 465, 'smtps', 'mail-pass-123'], [config('mail.default'), config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'), config('mail.mailers.smtp.scheme'), config('mail.mailers.smtp.password')]);
        $this->assertSame('no-reply@example.com', config('mail.from.address'));
        $this->assertSame('tenor-key-xyz', config('chat.gifs.tenor_key'));
        $this->assertSame('coturn-secret', config('chat.calls.turn_secret'));
        $this->assertSame('https://example.com/app.apk', config('chat.invite.url'));

        // A fresh start (new request / queue worker) reads them from MySQL too.
        config(['services.sms.twilio.token' => null]);
        app()->forgetInstance(AppConfigService::class);
        app(AppConfigService::class)->apply();
        $this->assertSame('super-secret-token', config('services.sms.twilio.token'));

        // Leaving a secret empty keeps it; "Remove" clears it and .env applies again.
        $this->save(['sms_driver' => 'twilio', 'sms_twilio_token' => '', 'tenor_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame('super-secret-token', app(AppConfigService::class)->value('sms_twilio_token'));
        $this->save(['sms_driver' => 'twilio', 'clear' => ['tenor_key']])->assertSessionHasNoErrors();
        $this->assertNull(app(AppConfigService::class)->value('tenor_key'));
        $this->assertSame(env('CHAT_TENOR_KEY'), config('chat.gifs.tenor_key'));

        // The audit log names what changed, never the values.
        $logs = AdminAuditLog::query()->where('action', 'settings.updated')->get();
        $this->assertStringContainsString('sms_twilio_token', $logs->first()->description);
        foreach (['super-secret-token', 'mail-pass-123'] as $secret) {
            $this->assertStringNotContainsString($secret, $logs->toJson());
        }
    }

    public function test_bad_values_are_refused(): void
    {
        $this->save(['default_country_code' => '92', 'sms_driver' => 'pigeon', 'sms_http_url' => 'not a url', 'mail_port' => '99999', 'mail_from_address' => 'nope', 'sms_http_to_field' => 'to field!'])
            ->assertSessionHasErrors(['default_country_code', 'sms_driver', 'sms_http_url', 'mail_port', 'mail_from_address', 'sms_http_to_field']);
        $this->assertNull(AppSetting::query()->find('sms_driver'));
    }

    public function test_test_sms_and_test_email_buttons(): void
    {
        Log::spy();
        $this->save(['sms_driver' => 'log', 'mail_mailer' => 'log'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->from('/admin/settings')->post('/admin/settings/test-sms', ['test_phone' => '0300 1234567'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Test SMS sent to +923001234567. (Log only: see storage/logs/laravel.log.)');
        Log::shouldHaveReceived('info')->withArgs(fn ($message) => str_contains($message, 'SMS to +923001234567'))->once();

        Mail::fake();
        $this->actingAs($this->admin)->from('/admin/settings')->post('/admin/settings/test-mail', ['test_email' => 'me@example.com'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->actingAs($this->admin)->post('/admin/settings/test-sms', ['test_phone' => '12'])->assertSessionHasErrorsIn('testSms', ['test_phone']);
        $this->actingAs(User::factory()->create())->post('/admin/settings/test-mail', ['test_email' => 'x@example.com'])->assertForbidden();
    }
}
