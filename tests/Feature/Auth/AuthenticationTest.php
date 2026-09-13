<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk()->assertSee('Welcome back');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/chat')->assertRedirect(route('login'));
        $this->get('/settings')->assertRedirect(route('login'));
    }

    public function test_users_can_login_with_email(): void
    {
        $user = User::factory()->create(['email' => 'sara@example.com']);

        $this->post('/login', ['login' => 'SARA@example.com', 'password' => 'Password1'])
            ->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->is_online);
    }

    public function test_users_can_login_with_username(): void
    {
        $user = User::factory()->create(['username' => 'sara']);

        $this->post('/login', ['login' => '@Sara', 'password' => 'Password1'])
            ->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_login_with_mobile_number(): void
    {
        $user = User::factory()->create(['phone' => '+923001234567']);

        $this->post('/login', ['login' => '+92 300 123-4567', 'password' => 'Password1'])
            ->assertRedirect(route('chat.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_numeric_usernames_still_work(): void
    {
        $user = User::factory()->create(['username' => '1234567']);

        $this->post('/login', ['login' => '1234567', 'password' => 'Password1']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_remember_me_sets_a_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => null]);

        $response = $this->post('/login', ['login' => $user->email, 'password' => 'Password1', 'remember' => '1']);

        $response->assertRedirect(route('chat.index'));
        $this->assertNotNull($user->fresh()->remember_token);
        $response->assertCookie(auth()->guard('web')->getRecallerName());
    }

    public function test_users_cannot_login_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['login' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['login' => $user->email, 'password' => 'Password1'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_suspended_and_inactive_users_cannot_login(): void
    {
        $suspended = User::factory()->suspended()->create();
        $inactive = User::factory()->inactive()->create();

        $this->post('/login', ['login' => $suspended->email, 'password' => 'Password1'])
            ->assertSessionHasErrors(['login' => 'Your account has been suspended. Please contact support.']);
        $this->assertGuest();

        $this->post('/login', ['login' => $inactive->email, 'password' => 'Password1'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_users_are_logged_out_when_their_account_is_suspended(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/chat')->assertOk();

        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->actingAs($user->fresh())->get('/chat')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->online()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertFalse($user->fresh()->is_online);
        $this->assertNotNull($user->fresh()->last_seen);
    }

    public function test_security_headers_are_present(): void
    {
        $this->get('/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
