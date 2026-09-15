<?php

namespace Tests\Feature\Auth;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Easier sign-up and login: name, mobile number and password; country code added for
 * you; username made from the name; email optional (admin panel setting).
 */
class EasySignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_up_needs_only_a_name_a_mobile_number_and_a_password(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('Your name')
            ->assertSee('+92 is added for you')
            ->assertDontSee('name="username"', false)
            ->assertDontSee('name="password_confirmation"', false);

        $this->post('/register', ['name' => 'Awais Ahmed', 'phone' => '0300 1234567', 'password' => 'easy1234'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('onboarding.photo'));

        $user = User::query()->where('phone', '+923001234567')->sole();
        $this->assertSame('awais.ahmed', $user->username);
        $this->assertNull($user->email);
        $this->assertAuthenticatedAs($user);

        // Someone with the same name gets the next free username.
        $this->post('/logout');
        $this->post('/register', ['name' => 'Awais Ahmed', 'phone' => '+44 7700 900123', 'password' => 'another99'])->assertSessionHasNoErrors();
        $this->assertTrue(User::query()->where('username', 'awais.ahmed2')->where('phone', '+447700900123')->exists());
    }

    public function test_passwords_need_letters_and_a_number_but_no_capital(): void
    {
        $this->post('/register', ['name' => 'Sara Khan', 'phone' => '03211234567', 'password' => 'abcdefgh'])->assertSessionHasErrors('password');
        $this->post('/register', ['name' => 'Sara Khan', 'phone' => '03211234567', 'password' => '12345678'])->assertSessionHasErrors('password');
        $this->post('/register', ['name' => 'Sara Khan', 'phone' => '03211234567', 'password' => 'sara2026'])->assertSessionHasNoErrors();
    }

    public function test_the_admin_panel_decides_about_the_email_and_the_country_code(): void
    {
        AppSetting::put(['signup_email' => 'required']);
        $this->get('/register')->assertSee('name="email"', false);
        $this->post('/register', ['name' => 'Bilal Ahmed', 'phone' => '03001112223', 'password' => 'bilal1234'])->assertSessionHasErrors('email');

        AppSetting::put(['signup_email' => 'hidden']);
        $this->get('/register')->assertDontSee('name="email"', false);
        $this->post('/register', ['name' => 'Bilal Ahmed', 'phone' => '03001112223', 'password' => 'bilal1234', 'email' => 'b@example.com'])->assertSessionHasErrors('email');

        // Another default country, or none (full number needed).
        AppSetting::put(['signup_email' => 'optional', 'default_country_code' => '+971']);
        $this->post('/register', ['name' => 'Bilal Ahmed', 'phone' => '050 123 4567', 'password' => 'bilal1234'])->assertSessionHasNoErrors();
        $this->assertTrue(User::query()->where('phone', '+971501234567')->exists());
        $this->post('/logout');
        AppSetting::put(['default_country_code' => null]);
        $this->post('/register', ['name' => 'Hina Tariq', 'phone' => '0333 7654321', 'password' => 'hina12345'])->assertSessionHasNoErrors();
        $this->assertTrue(User::query()->where('phone', '03337654321')->exists());
    }

    public function test_login_with_the_number_as_people_type_it_and_stay_signed_in_by_default(): void
    {
        $user = User::factory()->create(['phone' => '+923001234567']);

        $this->get('/login')->assertOk()->assertSee('name="remember" value="1" checked', false);
        $this->post('/login', ['login' => '0300 1234567', 'password' => 'Password1'])->assertRedirect(route('chat.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_accounts_without_an_email_can_use_everything_except_the_forgot_pin_link(): void
    {
        $user = User::factory()->create(['email' => null]);

        $this->actingAs($user)->get('/settings?tab=security')->assertOk()->assertSee('Add an email in');
        $this->actingAs($user)->post('/settings/two-step', ['pin' => '123456', 'pin_confirmation' => '123456', 'current_password' => 'Password1'])
            ->assertSessionHasErrorsIn('twoStep', ['pin']);
        $this->assertNull($user->fresh()->two_step_pin);

        // Adding an email later needs the password.
        $this->actingAs($user)->put('/settings/profile', ['name' => $user->name, 'username' => $user->username, 'email' => 'me@example.com'])
            ->assertSessionHasErrorsIn('profile', ['current_password']);
        $this->actingAs($user)->put('/settings/profile', ['name' => $user->name, 'username' => $user->username, 'email' => 'me@example.com', 'current_password' => 'Password1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('me@example.com', $user->fresh()->email);
    }
}
