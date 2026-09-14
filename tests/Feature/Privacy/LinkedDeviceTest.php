<?php

namespace Tests\Feature\Privacy;

use App\Models\LoginLink;
use App\Models\User;
use App\Services\TwoStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * P10 — Linked devices: log in on a computer by scanning its QR code with your phone.
 */
class LinkedDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    /** A browser that isn't signed in (tests share one session store). */
    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    public function test_a_phone_approves_the_qr_code_and_the_computer_signs_in(): void
    {
        $phone = User::factory()->create();

        $link = $this->withHeader('User-Agent', self::CHROME)->postJson('/login/qr')
            ->assertCreated()
            ->assertJsonStructure(['token', 'secret', 'code', 'url', 'expires_at'])
            ->json();
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $link['code']);
        $this->assertStringEndsWith('/link-device/'.$link['token'], $link['url']);
        $this->assertStringNotContainsString($link['secret'], json_encode(LoginLink::sole()));

        // The QR code alone (no secret) signs nobody in.
        $this->postJson("/login/qr/{$link['token']}/status", ['secret' => str_repeat('x', 40)])->assertJsonPath('state', 'expired');
        $this->postJson("/login/qr/{$link['token']}/status", ['secret' => $link['secret']])->assertJsonPath('state', 'waiting');

        // The phone sees which device asks, then approves (the typed code works too).
        $this->actingAs($phone)->get($link['url'])->assertOk()
            ->assertViewHas('chatConfig', fn (array $config) => $config['linkDevice'] === ['token' => $link['token']]);
        $this->actingAs($phone)->postJson('/linked-devices/lookup', ['token' => $link['token']])
            ->assertOk()->assertJsonPath('device', 'Chrome on Windows');
        $this->actingAs($phone)->postJson('/linked-devices/approve', ['code' => strtolower($link['code'])])
            ->assertOk()->assertJsonPath('approved', true);

        $this->asGuest();
        $this->postJson("/login/qr/{$link['token']}/status", ['secret' => $link['secret']])
            ->assertOk()->assertJsonPath('state', 'approved')->assertJsonPath('redirect', route('chat.index'));
        $this->assertAuthenticatedAs($phone);

        // Used once.
        $this->asGuest();
        $this->postJson("/login/qr/{$link['token']}/status", ['secret' => $link['secret']])->assertJsonPath('state', 'expired');
        $this->actingAs($phone)->postJson('/linked-devices/approve', ['token' => $link['token']])->assertNotFound();
    }

    public function test_expired_codes_guests_and_two_step_accounts(): void
    {
        $phone = User::factory()->create(['two_step_pin' => Hash::make('482913'), 'two_step_enabled_at' => now()]);

        $link = $this->postJson('/login/qr')->json();
        $this->postJson('/linked-devices/approve', ['token' => $link['token']])->assertUnauthorized();
        $this->actingAs($phone)->postJson('/linked-devices/lookup', [])->assertJsonValidationErrors('token');

        // Approved from the phone: no PIN on this computer, and it is remembered for the PIN.
        $this->actingAs($phone)->postJson('/linked-devices/approve', ['token' => $link['token']])->assertOk();
        $this->asGuest();
        $this->postJson("/login/qr/{$link['token']}/status", ['secret' => $link['secret']])
            ->assertJsonPath('state', 'approved')
            ->assertCookie(TwoStepService::COOKIE);
        $this->assertSame(1, $phone->trustedDevices()->count());

        $this->asGuest();
        $old = $this->postJson('/login/qr')->json();
        $this->travel(4)->minutes();
        $this->actingAs($phone)->postJson('/linked-devices/lookup', ['token' => $old['token']])
            ->assertNotFound()->assertJsonPath('message', 'This code has expired. Refresh the login page on the other device and scan the new code.');
        $this->asGuest();
        $this->postJson("/login/qr/{$old['token']}/status", ['secret' => $old['secret']])->assertJsonPath('state', 'expired');
        $this->get('/login')->assertOk()->assertSee('Log in with your phone');
    }
}
