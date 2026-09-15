<?php

namespace Tests\Feature\Account;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A1 — log in with the mobile number and an SMS code.
 */
class PhoneLoginTest extends TestCase
{
    use FakesSms;
    use RefreshDatabase;

    public function test_an_sms_code_logs_in_without_the_password(): void
    {
        $sms = $this->fakeSms();
        $user = User::factory()->create(['phone' => '+923001234567']);

        $this->get('/login')->assertOk()->assertSee('Log in with phone number');
        $this->get('/login/phone')->assertOk()->assertSee('Log in with your phone number');

        // Written differently from the account's number.
        $this->post('/login/phone', ['phone' => '0092 300 1234567', 'remember' => '1'])->assertRedirect('/login/phone/code');
        $this->assertCount(1, $sms->sent);
        $this->assertSame('+923001234567', $sms->sent[0]['to']);
        $this->assertStringContainsString('is your '.config('app.name').' login code', $sms->sent[0]['message']);
        $code = $sms->lastCode();
        $this->assertStringEndsWith("\n\n@".parse_url(config('app.url'), PHP_URL_HOST)." #{$code}", $sms->sent[0]['message']);
        $this->assertNotSame($code, OtpCode::query()->sole()->code_hash);

        $this->get('/login/phone/code')->assertOk()->assertSee('+923001234567')->assertSee('Send again in');

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post('/login/phone/code', ['code' => $wrong])->assertSessionHasErrors(['code' => 'That code is not correct.']);
        $this->assertGuest();

        $this->post('/login/phone/code', ['code' => $code])->assertRedirect('/chat');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertNotNull($user->fresh()->remember_token);

        // The code works once.
        $this->post('/logout');
        $this->post('/login/phone/code', ['code' => $code])->assertRedirect('/login/phone');
        $this->assertGuest();
    }

    public function test_a_number_without_an_account_gets_no_sms_but_looks_the_same(): void
    {
        $sms = $this->fakeSms();
        User::factory()->create(['phone' => '+923001234567', 'status' => User::STATUS_SUSPENDED]);

        foreach (['+447700900123', '+923001234567'] as $phone) {
            $this->post('/login/phone', ['phone' => $phone])->assertRedirect('/login/phone/code');
            $this->get('/login/phone/code')->assertOk()->assertSee('we\'ve sent it a 6-digit code', false);
            $this->post('/login/phone/code', ['code' => '123456'])->assertSessionHasErrors(['code' => 'That code is not correct.']);
            $this->travel(2)->minutes();
        }

        $this->assertSame([], $sms->sent);
        $this->assertSame(0, OtpCode::query()->count());
        $this->assertGuest();

        $this->post('/login/phone', ['phone' => '12ab'])->assertSessionHasErrors('phone');
    }

    public function test_codes_expire_and_wrong_codes_are_limited(): void
    {
        $sms = $this->fakeSms();
        User::factory()->create(['phone' => '+923001234567']);

        $this->post('/login/phone', ['phone' => '+923001234567']);
        $code = $sms->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $i) {
            $this->post('/login/phone/code', ['code' => $wrong])->assertSessionHasErrors(['code' => 'That code is not correct.']);
        }
        $this->post('/login/phone/code', ['code' => $wrong])->assertSessionHasErrors(['code' => 'Too many wrong codes. Ask for a new one.']);
        $this->travel(61)->seconds();
        $this->post('/login/phone/code', ['code' => $code])->assertSessionHasErrors(['code' => 'Too many wrong codes. Ask for a new one.']);
        $this->assertGuest();

        // A new code, left too long.
        $this->post('/login/phone/resend')->assertRedirect('/login/phone/code')->assertSessionHas('status', 'We sent you a new code.');
        $this->assertCount(2, $sms->sent);
        $this->travel(6)->minutes();
        $this->post('/login/phone/code', ['code' => $sms->lastCode()])->assertSessionHasErrors(['code' => 'This code has expired. Ask for a new one.']);
        $this->assertGuest();
    }

    public function test_sending_again_waits_a_minute_and_replaces_the_code(): void
    {
        $sms = $this->fakeSms();
        $user = User::factory()->create(['phone' => '+923001234567']);

        $this->post('/login/phone', ['phone' => '+923001234567']);
        $first = $sms->lastCode();

        $this->post('/login/phone/resend')->assertSessionHasErrors('code');
        $this->assertStringStartsWith('Wait ', session('errors')->first('code'));
        $this->assertCount(1, $sms->sent);

        $this->travel(61)->seconds();
        $this->post('/login/phone/resend')->assertSessionHas('status');
        $second = $sms->lastCode();
        $this->assertSame(1, OtpCode::query()->count());

        if ($first !== $second) {
            $this->post('/login/phone/code', ['code' => $first])->assertSessionHasErrors('code');
        }
        $this->post('/login/phone/code', ['code' => $second])->assertRedirect('/chat');
        $this->assertAuthenticatedAs($user);

        // Five codes an hour for one number.
        $this->post('/logout');
        foreach (range(1, 3) as $i) {
            $this->travel(61)->seconds();
            $this->post('/login/phone', ['phone' => '+923001234567'])->assertRedirect('/login/phone/code');
        }
        $this->travel(61)->seconds();
        $this->post('/login/phone', ['phone' => '+923001234567'])->assertSessionHasErrors('phone');
        $this->assertStringStartsWith('Too many codes were asked for.', session('errors')->first('phone'));
        $this->assertCount(5, $sms->sent);
    }

    public function test_two_step_verification_still_asks_for_the_pin(): void
    {
        $sms = $this->fakeSms();
        User::factory()->create(['phone' => '+923001234567', 'two_step_pin' => Hash::make('482913'), 'two_step_enabled_at' => now()]);

        $this->post('/login/phone', ['phone' => '+923001234567']);
        $this->post('/login/phone/code', ['code' => $sms->lastCode()])->assertRedirect('/login/verify');
        $this->assertGuest();

        $this->post('/login/verify', ['pin' => '482913'])->assertRedirect('/chat');
        $this->assertAuthenticated();
    }

    public function test_a_failed_sms_and_a_missing_gateway(): void
    {
        $sms = $this->fakeSms();
        $sms->fail = true;
        User::factory()->create(['phone' => '+923001234567']);

        $this->post('/login/phone', ['phone' => '+923001234567'])
            ->assertSessionHasErrors(['phone' => "We couldn't send the SMS right now. Try again in a few minutes."]);
        $this->assertSame(0, OtpCode::query()->count());

        // Trying again right away is allowed after a failure.
        $sms->fail = false;
        $this->post('/login/phone', ['phone' => '+923001234567'])->assertRedirect('/login/phone/code');

        // No gateway set up: no phone login.
        $this->app->forgetInstance(SmsService::class);
        config(['services.sms.driver' => 'twilio', 'services.sms.twilio.sid' => null]);
        $this->get('/login')->assertOk()->assertDontSee('Log in with phone number');
        $this->get('/login/phone')->assertNotFound();
    }
}
