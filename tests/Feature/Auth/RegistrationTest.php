<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Awais Ahmed',
            'username' => 'Awais_01',
            'email' => 'Awais@Example.com',
            'phone' => '+92 300-123 4567',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
        ], $overrides);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Create your account')
            ->assertDontSee('name="profile_image"', false);
    }

    public function test_new_users_can_register_with_normalized_fields(): void
    {
        $response = $this->post('/register', $this->payload());

        // Profile photo is added on a separate, optional step.
        $response->assertRedirect(route('onboarding.photo'));
        $this->assertAuthenticated();

        $user = User::firstWhere('username', 'awais_01');
        $this->assertNotNull($user);
        $this->assertSame('awais@example.com', $user->email);
        $this->assertSame('+923001234567', $user->phone);
        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertTrue($user->is_online);
        $this->assertNotSame('Secret123', $user->password);
    }

    public function test_registration_stores_a_processed_profile_image(): void
    {
        Storage::fake('public');

        $this->post('/register', $this->payload([
            'profile_image' => UploadedFile::fake()->image('me.jpg', 600, 400),
        ]))->assertRedirect(route('chat.index'));

        $user = User::firstWhere('username', 'awais_01');
        $this->assertNotNull($user->profile_image);
        $this->assertStringEndsWith('.webp', $user->profile_image);
        Storage::disk('public')->assertExists($user->profile_image);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($user->profile_image));
        $this->assertSame([256, 256], [$width, $height]);
    }

    public function test_username_email_and_phone_must_be_unique(): void
    {
        User::factory()->create(['username' => 'awais_01', 'email' => 'awais@example.com', 'phone' => '+923001234567']);

        $this->post('/register', $this->payload())
            ->assertSessionHasErrors(['username', 'email', 'phone']);

        $this->assertGuest();
    }

    public function test_registration_rejects_invalid_input(): void
    {
        $this->post('/register', $this->payload([
            'username' => 'ad min!',
            'email' => 'not-an-email',
            'phone' => '12',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]))->assertSessionHasErrors(['username', 'email', 'phone', 'password']);
    }

    public function test_reserved_usernames_are_rejected(): void
    {
        $this->post('/register', $this->payload(['username' => 'admin']))
            ->assertSessionHasErrors('username');
    }

    public function test_profile_image_must_be_an_allowed_image(): void
    {
        Storage::fake('public');

        $this->post('/register', $this->payload([
            'profile_image' => UploadedFile::fake()->create('avatar.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('profile_image');

        $this->post('/register', $this->payload([
            'profile_image' => UploadedFile::fake()->image('huge.jpg', 800, 800)->size(5000),
        ]))->assertSessionHasErrors('profile_image');

        $this->assertGuest();
    }

    public function test_authenticated_users_cannot_view_registration(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/register')
            ->assertRedirect(route('chat.index'));
    }

    /** Y2 — a referral code that is not a plain string must not break the page. */
    public function test_a_malformed_referral_code_still_renders_the_form(): void
    {
        $this->get('/register?ref[]=ABCD2345')->assertOk()->assertSee('Create your account');

        $this->post('/register', $this->payload(['ref' => ['ABCD2345']]))->assertSessionHasErrors('ref');
        $this->followingRedirects()->post('/register', $this->payload(['ref' => ['ABCD2345']]))->assertOk();

        $this->assertGuest();
    }
}
