<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_step_is_shown_after_registration(): void
    {
        $this->post('/register', [
            'name' => 'New Person',
            'username' => 'new.person',
            'email' => 'new@example.com',
            'phone' => '+923009998877',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
        ])->assertRedirect(route('onboarding.photo'));

        $this->get(route('onboarding.photo'))
            ->assertOk()
            ->assertSee('Add a profile photo')
            ->assertSee('Skip for now');
    }

    public function test_user_can_add_a_photo_and_continue_to_chat(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['profile_image' => null]);

        $this->actingAs($user)
            ->post(route('onboarding.photo.store'), ['profile_image' => UploadedFile::fake()->image('me.png', 400, 400)])
            ->assertRedirect(route('chat.index'))
            ->assertSessionHasNoErrors();

        $path = $user->fresh()->profile_image;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_photo_is_required_to_submit_and_validated(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('onboarding.photo.store'), [])->assertSessionHasErrors('profile_image');
        $this->actingAs($user)->post(route('onboarding.photo.store'), [
            'profile_image' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('profile_image');

        $this->assertNull($user->fresh()->profile_image);
    }

    public function test_guests_cannot_open_the_photo_step(): void
    {
        $this->get(route('onboarding.photo'))->assertRedirect(route('login'));
    }
}
