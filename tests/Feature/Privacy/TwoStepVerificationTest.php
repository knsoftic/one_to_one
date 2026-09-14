<?php

namespace Tests\Feature\Privacy;

use App\Models\User;
use App\Notifications\TwoStepResetNotification;
use App\Services\TwoStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * P7 — Two-step verification: PIN on new devices.
 */
class TwoStepVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(['password' => 'Password1'] + $attributes);
    }

    public function test_turning_it_on_needs_the_password_and_a_matching_pin(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/settings/two-step', ['pin' => '12345', 'pin_confirmation' => '12345', 'current_password' => 'Password1'])
            ->assertSessionHasErrors(['pin'], null, 'twoStep');
        $this->actingAs($user)->post('/settings/two-step', ['pin' => '123456', 'pin_confirmation' => '654321', 'current_password' => 'Password1'])
            ->assertSessionHasErrors(['pin'], null, 'twoStep');
        $this->actingAs($user)->post('/settings/two-step', ['pin' => '123456', 'pin_confirmation' => '123456', 'current_password' => 'wrong'])
            ->assertSessionHasErrors(['current_password'], null, 'twoStep');

        $this->actingAs($user)->post('/settings/two-step', ['pin' => '482913', 'pin_confirmation' => '482913', 'current_password' => 'Password1'])
            ->assertRedirect('/settings?tab=security')
            ->assertCookie(TwoStepService::COOKIE);

        $user->refresh();
        $this->assertTrue(Hash::check('482913', $user->two_step_pin));
        $this->assertNotSame('482913', $user->two_step_pin);
        $this->assertSame(1, $user->trustedDevices()->count());
        $this->actingAs($user)->get('/settings?tab=security')->assertSee('Change PIN')->assertSee('1 trusted browser');
    }

    public function test_a_new_browser_is_asked_for_the_pin_and_is_trusted_after(): void
    {
        $user = $this->user(['two_step_pin' => Hash::make('482913'), 'two_step_enabled_at' => now()]);

        $this->post('/login', ['login' => $user->email, 'password' => 'Password1', 'remember' => '1'])
            ->assertRedirect('/login/verify');
        $this->assertGuest();
        $this->get('/chat')->assertRedirect('/login');
        $this->get('/login/verify')->assertOk()->assertSee('Two-step verification');

        $this->post('/login/verify', ['pin' => '111111'])->assertSessionHasErrors(['pin' => 'That PIN is not correct.']);
        $this->assertGuest();

        $response = $this->post('/login/verify', ['pin' => '482913'])->assertRedirect('/chat');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === TwoStepService::COOKIE);
        $this->assertNotNull($cookie);

        // Same browser next time: no PIN.
        $this->post('/logout');
        $this->withUnencryptedCookie(TwoStepService::COOKIE, $cookie->getValue())
            ->post('/login', ['login' => $user->email, 'password' => 'Password1'])
            ->assertRedirect('/chat');
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_pins_are_limited_and_a_wrong_password_never_reaches_the_pin(): void
    {
        $user = $this->user(['two_step_pin' => Hash::make('482913')]);

        $this->post('/login', ['login' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('login');
        $this->get('/login/verify')->assertRedirect('/login');

        $this->post('/login', ['login' => $user->email, 'password' => 'Password1'])->assertRedirect('/login/verify');
        foreach (range(1, 5) as $i) {
            $this->post('/login/verify', ['pin' => '000000']);
        }
        // Too many tries: even the right PIN is refused for now.
        $this->post('/login/verify', ['pin' => '482913'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_forgot_pin_emails_a_link_that_turns_it_off(): void
    {
        Notification::fake();
        $user = $this->user(['two_step_pin' => Hash::make('482913'), 'two_step_enabled_at' => now()]);

        $this->post('/login', ['login' => $user->email, 'password' => 'Password1']);
        $this->post('/login/verify/forgot')->assertSessionHas('status');
        Notification::assertSentTo($user, TwoStepResetNotification::class);

        $this->get('/two-step/reset/'.$user->id)->assertForbidden();
        $this->get(URL::temporarySignedRoute('two-step.reset', now()->addHour(), ['user' => $user->id]))->assertRedirect('/login');
        $this->assertNull($user->fresh()->two_step_pin);

        $this->post('/login', ['login' => $user->email, 'password' => 'Password1'])->assertRedirect('/chat');
    }

    public function test_changing_turning_off_and_forgetting_browsers(): void
    {
        $user = $this->user(['two_step_pin' => Hash::make('482913'), 'two_step_enabled_at' => now()]);
        $user->trustedDevices()->create(['token_hash' => str_repeat('a', 64), 'name' => 'Chrome on Windows']);

        $this->actingAs($user)->put('/settings/two-step', ['pin' => '777111', 'pin_confirmation' => '777111', 'current_password' => 'Password1'])->assertRedirect();
        $this->assertTrue(Hash::check('777111', $user->fresh()->two_step_pin));

        $this->actingAs($user)->delete('/settings/two-step/devices')->assertRedirect();
        $this->assertSame(0, $user->trustedDevices()->count());

        $this->actingAs($user)->delete('/settings/two-step', ['current_password' => 'wrong'])->assertSessionHasErrors(['current_password'], null, 'twoStep');
        $this->actingAs($user)->delete('/settings/two-step', ['current_password' => 'Password1'])->assertRedirect();
        $this->assertNull($user->fresh()->two_step_pin);
    }
}
